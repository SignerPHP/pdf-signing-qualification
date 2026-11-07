<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Panel;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Panel\StrataClassifier;
use SignerPHP\PdfSigningQualification\Panel\UnclassifiableFile;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\HybridXrefPdf;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\SyntheticPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;

final class StrataClassifierTest extends TestCase
{
    private const string SIGNATURE_TIME_STAMP_TOKEN = '060b2a864886f70d010910020e0500';

    private const string FILLED_REVOCATION_ARCHIVE = '06092a864886f72f01010831053003a00100';

    private const string EMPTY_REVOCATION_ARCHIVE = '06092a864886f72f01010831023000';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/strata-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    private static function classifier(int $largeByteSize = 1_000_000, int $largePageCount = 1000): StrataClassifier
    {
        return new StrataClassifier(new ProcessRunner, $largeByteSize, $largePageCount);
    }

    private function classify(string $document, ?StrataClassifier $classifier = null): array
    {
        return ($classifier ?? self::classifier())->classify(SyntheticPdf::write($this->directory, 'input.pdf', $document));
    }

    private static function signature(string $contents, string $subFilter = '/adbe.pkcs7.detached'): string
    {
        return "<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter {$subFilter} /ByteRange [0 10 20 30] /Contents <{$contents}> >>";
    }

    /**
     * @return array<int, string>
     */
    private static function signedDocument(string $signature): array
    {
        return [
            1 => '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [4 0 R] /SigFlags 3 >> >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Annots [4 0 R] >>',
            4 => '<< /FT /Sig /T (Signature1) /V 5 0 R /Type /Annot /Subtype /Widget /Rect [0 0 0 0] /P 3 0 R >>',
            5 => $signature,
        ];
    }

    public function test_one_revision_with_a_table_is_classic(): void
    {
        self::assertSame(['classic'], $this->classify(SyntheticPdf::classic(SyntheticPdf::onePage())));
    }

    public function test_more_than_one_startxref_is_incremental(): void
    {
        $document = SyntheticPdf::appendUpdate(SyntheticPdf::classic(SyntheticPdf::onePage()), [3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 200] >>']);

        self::assertSame(['classic', 'incremental'], $this->classify($document));
    }

    public function test_linearized_file_with_one_revision_is_not_incremental(): void
    {
        $linearized = $this->linearized();

        self::assertSame(2, substr_count((string) file_get_contents($linearized), 'startxref'));
        self::assertNotContains('incremental', self::classifier()->classify($linearized));
    }

    public function test_linearized_file_with_an_update_is_incremental(): void
    {
        $document = SyntheticPdf::appendUpdate((string) file_get_contents($this->linearized()), [3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 200] >>']);

        self::assertContains('incremental', $this->classify($document));
    }

    public function test_table_with_a_damaged_subsection_header_is_classic_and_malformed(): void
    {
        $document = str_replace("xref\n0 1\n", "xref\nx0 1\n", SyntheticPdf::classic(SyntheticPdf::onePage()));

        self::assertSame(['classic', 'malformed'], $this->classify($document));
    }

    public function test_table_keyword_after_endobj_on_the_same_line_is_classic(): void
    {
        $document = (string) preg_replace('/endobj\nxref\n/', "endobj xref\n", SyntheticPdf::classic(SyntheticPdf::onePage()), 1, $replacements);
        self::assertSame(1, $replacements);

        self::assertSame(['classic'], $this->classify($document));
    }

    public function test_table_and_stream_in_one_revision_without_xrefstm_have_no_cross_reference_stratum(): void
    {
        $objects = SyntheticPdf::onePage() + [4 => '<< /Type /XRef >>'];

        self::assertSame([], $this->classify(SyntheticPdf::classic($objects)));
    }

    public function test_qpdf_that_does_not_finish_stops_the_classification(): void
    {
        $path = SyntheticPdf::write($this->directory, 'input.pdf', SyntheticPdf::classic(SyntheticPdf::onePage()));
        $runner = new class implements CommandRunner
        {
            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                return new CommandResult(null, '', timedOut: true);
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectException(UnclassifiableFile::class);
        $this->expectExceptionMessage('qpdf did not finish in time');

        (new StrataClassifier($runner, 1_000_000, 1000))->classify($path);
    }

    public function test_qpdf_check_that_ends_with_an_unknown_status_stops_the_classification(): void
    {
        $path = SyntheticPdf::write($this->directory, 'input.pdf', SyntheticPdf::classic(SyntheticPdf::onePage()));
        $runner = new class implements CommandRunner
        {
            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                return new CommandResult(in_array('--check', $command, true) ? 139 : 0, '{}');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectException(UnclassifiableFile::class);
        $this->expectExceptionMessage('qpdf exited with code 139');

        (new StrataClassifier($runner, 1_000_000, 1000))->classify($path);
    }

    private function linearized(): string
    {
        $input = SyntheticPdf::write($this->directory, 'plain.pdf', SyntheticPdf::classic(SyntheticPdf::onePage()));
        $output = $this->directory.'/linearized.pdf';
        exec('qpdf --linearize '.escapeshellarg($input).' '.escapeshellarg($output), result_code: $exitCode);
        self::assertSame(0, $exitCode);

        return $output;
    }

    public function test_startxref_that_points_to_no_cross_reference_is_none_and_malformed(): void
    {
        $document = preg_replace('/startxref\n\d+/', "startxref\n9", SyntheticPdf::classic(SyntheticPdf::onePage()));

        self::assertSame(['none', 'malformed'], $this->classify((string) $document));
    }

    public function test_trailer_pointing_to_a_cross_reference_stream_is_hybrid(): void
    {
        $path = HybridXrefPdf::create($this->directory);

        self::assertSame(['hybrid', 'objstm'], self::classifier()->classify($path));
    }

    public function test_object_streams_and_standard_encryption_are_read_from_a_rewritten_file(): void
    {
        $input = SyntheticPdf::write($this->directory, 'plain.pdf', SyntheticPdf::classic(SyntheticPdf::onePage()));
        $output = $this->directory.'/encrypted.pdf';
        exec('qpdf --object-streams=generate --encrypt "" owner 256 -- '.escapeshellarg($input).' '.escapeshellarg($output), result_code: $exitCode);
        self::assertSame(0, $exitCode);

        self::assertSame(['stream', 'objstm', 'encrypted'], self::classifier()->classify($output));
    }

    #[DataProvider('legacyVersions')]
    public function test_header_below_pdf_1_3_is_legacy(string $version, bool $legacy): void
    {
        $strata = $this->classify(SyntheticPdf::classic(SyntheticPdf::onePage(), $version));

        self::assertSame($legacy, in_array('legacy', $strata, true));
    }

    public static function legacyVersions(): iterable
    {
        yield 'PDF 1.0' => ['1.0', true];
        yield 'PDF 1.2' => ['1.2', true];
        yield 'PDF 1.3' => ['1.3', false];
        yield 'PDF 2.0' => ['2.0', false];
    }

    public function test_field_other_than_a_signature_is_a_form(): void
    {
        $objects = [
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Annots [4 0 R] >>',
            4 => '<< /FT /Tx /T (Name) /Type /Annot /Subtype /Widget /Rect [10 10 100 30] /P 3 0 R >>',
        ] + SyntheticPdf::onePage('/AcroForm << /Fields [4 0 R] >>');
        ksort($objects);

        self::assertSame(['classic', 'forms'], $this->classify(SyntheticPdf::classic($objects)));
    }

    public function test_signature_field_alone_is_signed_and_not_a_form(): void
    {
        $strata = $this->classify(SyntheticPdf::classic(self::signedDocument(self::signature('3000'))));

        self::assertSame(['classic', 'signed'], $strata);
    }

    public function test_signature_with_a_timestamp_token_is_timestamped(): void
    {
        $strata = $this->classify(SyntheticPdf::classic(self::signedDocument(self::signature('3082'.self::SIGNATURE_TIME_STAMP_TOKEN))));

        self::assertSame(['classic', 'signed', 'timestamped'], $strata);
    }

    public function test_document_timestamp_is_timestamped(): void
    {
        $strata = $this->classify(SyntheticPdf::classic(self::signedDocument(self::signature('3000', '/ETSI.RFC3161'))));

        self::assertSame(['classic', 'signed', 'timestamped'], $strata);
    }

    public function test_signature_with_filled_revocation_archive_has_revocation(): void
    {
        $strata = $this->classify(SyntheticPdf::classic(self::signedDocument(self::signature('3082'.self::FILLED_REVOCATION_ARCHIVE))));

        self::assertSame(['classic', 'signed', 'revocation'], $strata);
    }

    public function test_signature_with_empty_revocation_archive_has_no_revocation(): void
    {
        $strata = $this->classify(SyntheticPdf::classic(self::signedDocument(self::signature('3082'.self::EMPTY_REVOCATION_ARCHIVE))));

        self::assertSame(['classic', 'signed'], $strata);
    }

    public function test_document_security_store_has_revocation(): void
    {
        $objects = SyntheticPdf::onePage('/DSS 4 0 R') + [4 => '<< /Certs [] /OCSPs [] /CRLs [] >>'];

        self::assertSame(['classic', 'revocation'], $this->classify(SyntheticPdf::classic($objects)));
    }

    public function test_byte_size_at_the_threshold_is_large(): void
    {
        $document = SyntheticPdf::classic(SyntheticPdf::onePage());

        self::assertContains('large', $this->classify($document, self::classifier(largeByteSize: strlen($document))));
        self::assertNotContains('large', $this->classify($document, self::classifier(largeByteSize: strlen($document) + 1)));
    }

    public function test_page_count_at_the_threshold_is_large(): void
    {
        $document = SyntheticPdf::classic(SyntheticPdf::onePage());

        self::assertContains('large', $this->classify($document, self::classifier(largePageCount: 1)));
        self::assertNotContains('large', $this->classify($document, self::classifier(largePageCount: 2)));
    }

    public function test_file_that_qpdf_cannot_open_is_classified_from_its_bytes(): void
    {
        $strata = $this->classify('%PDF-1.1 not a document /ByteRange [0 1 2 3] /Contents <'.self::SIGNATURE_TIME_STAMP_TOKEN.'>');

        self::assertSame(['none', 'signed', 'malformed', 'legacy', 'timestamped'], $strata);
    }
}
