<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Validation\BaselineSubject;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\ComparisonValidator;
use SignerPHP\PdfSigningQualification\Validation\InputPreservationValidator;
use SignerPHP\PdfSigningQualification\Validation\PriorSignaturesValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\SignatureCoverageValidator;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class ComparisonValidatorsTest extends TestCase
{
    private const string ATTEMPT_ID = 'run-20261107T120000Z/synthetic/basic-invisible-sha256/synthetic-signer/1';

    private const array VALIDATORS = [InputPreservationValidator::class, PriorSignaturesValidator::class, SignatureCoverageValidator::class];

    private const string SIGNED_CATALOG_START = '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [5 0 R] /SigFlags 3 >>';

    private const string APPENDED_CATALOG_START = '<< /Type /Catalog /Pages 2 0 R /AcroForm << /Fields [5 0 R 7 0 R] /SigFlags 3 >>';

    private const string DSS_ENTRIES = ' /DSS 9 0 R /Extensions << /ESIC << /BaseVersion /1.7 /ExtensionLevel 1 >> >>';

    private const array DSS_OBJECTS = [9 => '<< /Type /DSS /Certs [10 0 R] /VRI << /ABCDEF << /Cert [10 0 R] >> >> >>', 10 => "<< /Length 4 >>\nstream\ncert\nendstream"];

    private static string $directory;

    /** @var array<string, string> */
    private static array $fixtures;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/comparison-validators-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);

        $signed = SyntheticSignedPdf::create(self::$directory);
        $unsigned = SyntheticSignedPdf::createUnsigned(self::$directory);
        $appendedToSigned = SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-to-signed');
        $dssSigned = SyntheticSignedPdf::appendRevision($signed, self::$directory, 'signed-dss-revision', [1 => self::SIGNED_CATALOG_START.self::DSS_ENTRIES.' >>'] + self::DSS_OBJECTS, 11);
        $signatureAfterDss = SyntheticSignedPdf::appendSignature($dssSigned, self::$directory, 'signature-after-dss-revision', trailerSize: 11);
        $pagesInObjectStream = SyntheticSignedPdf::appendObjectStreamRevision($signed, self::$directory, 'signed-pages-in-object-stream', 9, [2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>'], 11);
        $twiceSignedObjectStream = SyntheticSignedPdf::appendSignature(SyntheticSignedPdf::appendSignature($pagesInObjectStream, self::$directory, 'object-stream-signed-once', trailerSize: 13, firstObject: 11), self::$directory, 'object-stream-signed-twice', trailerSize: 15, firstObject: 13);
        $shiftedGap = SyntheticSignedPdf::shiftLastByteRangeGap($appendedToSigned, self::$directory);
        $leadingBytes = self::$directory.'/leading-bytes.pdf';
        file_put_contents($leadingBytes, "leading junk\n\n".file_get_contents($unsigned));

        self::$fixtures = [
            'signed' => $signed,
            'unsigned' => $unsigned,
            'leading bytes' => $leadingBytes,
            'appended to signed' => $appendedToSigned,
            'appended to unsigned' => SyntheticSignedPdf::appendSignature($unsigned, self::$directory, 'appended-to-unsigned'),
            'appended to tampered' => SyntheticSignedPdf::appendSignature(SyntheticSignedPdf::tamperSignedRange($signed, self::$directory), self::$directory, 'appended-to-tampered'),
            'appended without the prior field' => SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-without-prior-field', keepPriorFields: false),
            'appended with bytes after the range' => SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-with-trailing-bytes', unsignedSuffix: "% unsigned\n"),
            'appended with a shifted gap' => $shiftedGap,
            'object stream signed twice' => $twiceSignedObjectStream,
            'object stream signed twice and rewritten' => SyntheticSignedPdf::appendObjectStreamRevision($twiceSignedObjectStream, self::$directory, 'object-stream-rewritten', 9, [2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 /Rotate 90 >>'], 17),
            'object stream input' => $pagesInObjectStream,
            'appended with a shifted gap and a document information dictionary' => SyntheticSignedPdf::appendRevision($shiftedGap, self::$directory, 'shifted-gap-information', [9 => '<< /Producer (Synthetic) >>'], 10, ' /Info 9 0 R'),
            'document timestamp appended to signed' => SyntheticSignedPdf::appendSignature($signed, self::$directory, 'timestamp-appended-to-signed', signatureType: '/DocTimeStamp'),
            'appended to signed with a DSS revision' => SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-dss-revision', [1 => self::APPENDED_CATALOG_START.self::DSS_ENTRIES.' >>'] + self::DSS_OBJECTS, 11),
            'certification appended to signed with a DSS revision' => SyntheticSignedPdf::appendRevision(SyntheticSignedPdf::appendSignature($signed, self::$directory, 'certification-appended-to-signed', certification: true), self::$directory, 'certification-appended-to-signed-dss-revision', [1 => self::APPENDED_CATALOG_START.' /Perms << /DocMDP 8 0 R >>'.self::DSS_ENTRIES.' >>'] + self::DSS_OBJECTS, 11),
            'appended to signed with a content change' => SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-content-change', [4 => "<< /Length 43 >>\nstream\nBT /F1 12 Tf 20 100 Td (Changed text) Tj ET\nendstream"]),
            'appended to signed with a rewritten page tree' => SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-page-tree', [2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>']),
            'appended to signed with a DSS and a content change' => SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-dss-content-change', [1 => self::APPENDED_CATALOG_START.self::DSS_ENTRIES.' >>', 4 => "<< /Length 43 >>\nstream\nBT /F1 12 Tf 20 100 Td (Changed text) Tj ET\nendstream"] + self::DSS_OBJECTS, 11),
            'appended to signed with a document information dictionary' => SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-information', [9 => '<< /Producer (Synthetic) >>'], 10, ' /Info 9 0 R'),
            'appended to signed with an information dictionary it already holds' => SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-existing-information', [], 9, ' /Info 2 0 R'),
            'appended to signed with a catalog change undone' => SyntheticSignedPdf::appendRevision(SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-full-screen', [1 => self::APPENDED_CATALOG_START.' /PageMode /FullScreen >>']), self::$directory, 'appended-to-signed-full-screen-undone', [1 => self::APPENDED_CATALOG_START.' >>']),
            'signature after a DSS revision followed by bytes' => SyntheticSignedPdf::appendSignature($dssSigned, self::$directory, 'signature-after-dss-revision-and-bytes', unsignedPrefix: "% unsigned\n", trailerSize: 11),
            'appended to signed with an orphan object' => SyntheticSignedPdf::appendRevision($appendedToSigned, self::$directory, 'appended-to-signed-orphan', [9 => '<< /Orphan true >>'], 10),
            'signature after a DSS revision' => $signatureAfterDss,
            'signature after a DSS revision with a first range over it' => SyntheticSignedPdf::rewriteFirstByteRange($signatureAfterDss, self::$directory, 'first-range-over-the-dss-revision', static fn (int $firstLength, int $secondOffset): string => sprintf('/ByteRange [0 %d %d %d]', $firstLength, $secondOffset, (int) filesize($dssSigned) - $secondOffset)),
            'appended to signed with blank lines between the revisions' => SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-with-blank-lines-between-revisions', unsignedPrefix: str_repeat("\n", 200)),
            'appended to signed with bytes between the revisions' => SyntheticSignedPdf::appendSignature($signed, self::$directory, 'appended-with-bytes-between-revisions', unsignedPrefix: "% unsigned\n"),
            'appended to signed with a first range short of its revision' => SyntheticSignedPdf::rewriteFirstByteRange($appendedToSigned, self::$directory, 'first-range-short-of-its-revision', static fn (int $firstLength, int $secondOffset, int $secondLength): string => sprintf('/ByteRange [0 %d %d %d]', $firstLength, $secondOffset, $secondLength - 2)),
            'appended to signed with a first range over the next revision' => SyntheticSignedPdf::rewriteFirstByteRange($appendedToSigned, self::$directory, 'first-range-over-the-next-revision', static fn (int $firstLength, int $secondOffset): string => sprintf('/ByteRange [0 %d %d %d]', $firstLength, $secondOffset, (int) filesize($appendedToSigned) - $secondOffset)),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    /**
     * @param  class-string<ToolValidator>  $validatorClass
     */
    private static function validator(string $validatorClass): ToolValidator
    {
        return new $validatorClass(new ProcessRunner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-07T12:00:03Z'));
    }

    private static function subject(string $input, ?string $output): ValidationSubject
    {
        $inputPath = self::$fixtures[$input];

        return new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $output === null ? null : self::$fixtures[$output], $inputPath);
    }

    private static function subjectOf(string $input, string $outputPath): ValidationSubject
    {
        $inputPath = self::$fixtures[$input];

        return new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath);
    }

    public static function expectedVerdicts(): iterable
    {
        $cases = [
            'intact prior signature' => ['signed', 'appended to signed', ['valid', 'valid', 'valid']],
            'unsigned input' => ['unsigned', 'appended to unsigned', ['valid', 'not_applicable', 'valid']],
            'prior signed bytes changed' => ['signed', 'appended to tampered', ['invalid', 'invalid', 'valid']],
            'prior signature removed from the fields' => ['signed', 'appended without the prior field', ['valid', 'invalid', 'valid']],
            'bytes appended after signing' => ['signed', 'appended with bytes after the range', ['valid', 'valid', 'invalid']],
            'range gap shifted after signing' => ['signed', 'appended with a shifted gap', ['valid', 'valid', 'invalid']],
            'bytes before the header dropped' => ['leading bytes', 'appended to unsigned', ['invalid', 'not_applicable', 'valid']],
            'no signature added' => ['signed', 'signed', ['valid', 'valid', 'invalid']],
        ];

        foreach ($cases as $name => [$input, $output, $verdicts]) {
            foreach (self::VALIDATORS as $index => $validatorClass) {
                yield "{$name}, ".self::validator($validatorClass)->analyzerId() => [$validatorClass, $input, $output, $verdicts[$index]];
            }
        }
    }

    /**
     * @param  class-string<ComparisonValidator>  $validatorClass
     */
    #[DataProvider('expectedVerdicts')]
    public function test_validator_compares_the_output_with_its_input(string $validatorClass, string $input, string $output, string $verdict): void
    {
        $validator = self::validator($validatorClass);

        $observation = $validator->observe(self::subject($input, $output));

        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));
        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']."\n".$observation['evidence']['raw_output']);
        self::assertSame(self::ATTEMPT_ID.'/'.$validator->analyzerId(), $observation['id']);
        self::assertSame(Artifact::describe(self::$fixtures[$input]), $observation['input_artifact']);
        self::assertSame(Artifact::describe(self::$fixtures[$output]), $observation['output_artifact']);
    }

    public function test_coverage_identifies_the_signature_added_by_the_implementation(): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('signed', 'appended to signed'));

        $signatures = $observation['evidence']['details']['signatures'];
        self::assertCount(1, $signatures);
        self::assertSame(['object' => '8 0 R', 'field' => 'Signature2', 'outcome' => 'valid'], array_intersect_key($signatures[0], ['object' => true, 'field' => true, 'outcome' => true]));
        [, $firstLength, $secondOffset, $secondLength] = $signatures[0]['byte_range'];
        self::assertSame(filesize(self::$fixtures['appended to signed']), $secondOffset + $secondLength);
        self::assertSame('Signature2 covers every byte of the output except its /Contents', $observation['evidence']['summary']);
        self::assertGreaterThan($firstLength, $secondOffset);
    }

    public function test_field_name_that_is_not_text_is_kept_as_qpdf_gives_it(): void
    {
        $outputPath = SyntheticSignedPdf::appendSignature(self::$fixtures['signed'], self::$directory, 'binary-field-name', fieldName: '<F9934D8A>');
        $subject = new ValidationSubject(self::ATTEMPT_ID, Artifact::describe(self::$fixtures['signed']), $outputPath, self::$fixtures['signed']);

        $observation = self::validator(SignatureCoverageValidator::class)->observe($subject);

        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));
        self::assertSame('b:f9934d8a covers every byte of the output except its /Contents', $observation['evidence']['summary']);
    }

    private static function alteredValidator(string $validatorClass, string $documentPath, callable $alter): ToolValidator
    {
        return new $validatorClass(new AlteredListingRunner($documentPath, $alter(...)), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-07T12:00:03Z'));
    }

    public function test_prior_signatures_of_an_input_qpdf_had_to_recover_are_not_all_known(): void
    {
        $validator = self::alteredValidator(PriorSignaturesValidator::class, self::$fixtures['signed'], static fn (CommandResult $result): CommandResult => new CommandResult(3, $result->output));

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('The output keeps the 1 signature(s) qpdf listed in the input unchanged, but qpdf had to recover the input, so it may not have listed every signature', $observation['evidence']['summary']);
    }

    public function test_signature_lost_from_a_recovered_input_listing_is_not_judged_as_the_added_one(): void
    {
        $unsignedListing = AlteredListingRunner::listingOf(self::$fixtures['unsigned']);
        $validator = self::alteredValidator(SignatureCoverageValidator::class, self::$fixtures['signed'], static fn (CommandResult $result): CommandResult => new CommandResult(3, $unsignedListing));

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        $uncovered = filesize(self::$fixtures['appended to signed']) - filesize(self::$fixtures['signed']);
        self::assertSame("Signature1: the byte range does not reach the end of the file, leaving {$uncovered} bytes uncovered, but qpdf had to recover the input, so this may be a signature of the input", $observation['evidence']['summary']);
        self::assertSame(['Signature1' => 'inconclusive', 'Signature2' => 'valid'], array_column($observation['evidence']['details']['signatures'], 'outcome', 'field'));
    }

    public function test_signature_lost_from_a_recovered_input_listing_does_not_let_a_later_revision_pass(): void
    {
        $unsignedListing = AlteredListingRunner::listingOf(self::$fixtures['unsigned']);
        $validator = self::alteredValidator(SignatureCoverageValidator::class, self::$fixtures['signed'], static fn (CommandResult $result): CommandResult => new CommandResult(3, $unsignedListing));

        $observation = $validator->observe(self::subject('signed', 'appended to signed with a DSS revision'));

        self::assertSame('inconclusive', $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame(['Signature1' => 'inconclusive', 'Signature2' => 'inconclusive'], array_column($observation['evidence']['details']['signatures'], 'outcome', 'field'));
        self::assertArrayNotHasKey('unsigned_revisions', $observation['evidence']['details']);
    }

    public function test_prior_signature_whose_range_extends_past_the_end_of_the_input_is_inconclusive(): void
    {
        $inputPath = SyntheticSignedPdf::rewriteFirstByteRange(self::$fixtures['signed'], self::$directory, 'range-past-end', static fn (int $firstLength, int $secondOffset, int $secondLength): string => sprintf('/ByteRange [0 %d %d %d]', $firstLength, $secondOffset, $secondLength + 1000));
        $outputPath = SyntheticSignedPdf::appendSignature($inputPath, self::$directory, 'appended-to-range-past-end');

        $observation = self::validator(PriorSignaturesValidator::class)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature1: its byte range extends past the end of the input, so the input does not hold every byte it covers', $observation['evidence']['summary']);
    }

    public function test_prior_signature_whose_byte_range_cannot_be_read_is_inconclusive(): void
    {
        $inputPath = SyntheticSignedPdf::rewriteFirstByteRange(self::$fixtures['signed'], self::$directory, 'three-element-range', static fn (int $firstLength, int $secondOffset): string => sprintf('/ByteRange [0 %d %d]', $firstLength, $secondOffset));
        $outputPath = SyntheticSignedPdf::appendSignature($inputPath, self::$directory, 'appended-to-three-element-range');

        $observation = self::validator(PriorSignaturesValidator::class)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($inputPath), $outputPath, $inputPath));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature1: its /ByteRange or /Contents in the input cannot be read', $observation['evidence']['summary']);
    }

    public function test_prior_signature_missing_from_an_output_qpdf_had_to_recover_is_inconclusive(): void
    {
        $unsignedListing = AlteredListingRunner::listingOf(self::$fixtures['unsigned']);
        $validator = self::alteredValidator(PriorSignaturesValidator::class, self::$fixtures['appended to signed'], static fn (CommandResult $result): CommandResult => new CommandResult(3, $unsignedListing));

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature1: no dictionary with the same /ByteRange and /Contents was found in an output qpdf had to recover', $observation['evidence']['summary']);
    }

    public function test_added_signature_whose_contents_qpdf_does_not_give_is_inconclusive(): void
    {
        $withoutContents = static function (CommandResult $result): CommandResult {
            $listing = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
            $listing['qpdf'][1]['obj:8 0 R']['value']['/Contents'] = 'u:é';

            return new CommandResult($result->exitCode, json_encode($listing, JSON_THROW_ON_ERROR));
        };
        $validator = self::alteredValidator(SignatureCoverageValidator::class, self::$fixtures['appended to signed'], $withoutContents);

        $observation = $validator->observe(self::subject('signed', 'appended to signed'));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('Signature2: qpdf did not give the bytes of its /Contents, so the bytes the range leaves out cannot be explained', $observation['evidence']['summary']);
    }

    public static function coverageProblems(): iterable
    {
        yield 'bytes after the range' => ['appended with bytes after the range', 'Signature2: the byte range does not reach the end of the file, leaving 11 bytes uncovered'];
        yield 'shifted gap' => ['appended with a shifted gap', 'Signature2: the bytes the range leaves out are not the hexadecimal string of its /Contents'];
        yield 'no signature added' => ['signed', 'The output holds no signature that is not already in the input'];
    }

    #[DataProvider('coverageProblems')]
    public function test_coverage_names_the_bytes_it_cannot_explain(string $output, string $summary): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('signed', $output));

        self::assertSame($summary, $observation['evidence']['summary']);
    }

    public static function outputsAddingSeveralSignatures(): iterable
    {
        yield 'two approval signatures' => ['appended to signed', 'valid', ['Signature1' => 'valid', 'Signature2' => 'valid']];
        yield 'signature followed by a document timestamp' => ['document timestamp appended to signed', 'valid', ['Signature1' => 'valid', 'Signature2' => 'valid']];
        yield 'DSS revision after the last signature' => ['appended to signed with a DSS revision', 'valid', ['Signature1' => 'valid', 'Signature2' => 'valid']];
        yield 'DSS revision after a certification whose reference names the catalog' => ['certification appended to signed with a DSS revision', 'valid', ['Signature1' => 'valid', 'Signature2' => 'valid']];
        yield 'DSS revision between the signatures' => ['signature after a DSS revision', 'valid', ['Signature1' => 'valid', 'Signature2' => 'valid']];
        yield 'blank lines between the revisions' => ['appended to signed with blank lines between the revisions', 'valid', ['Signature1' => 'valid', 'Signature2' => 'valid']];
        yield 'content changed after the last signature' => ['appended to signed with a content change', 'invalid', ['Signature1' => 'valid', 'Signature2' => 'invalid']];
        yield 'page tree rewritten after the last signature' => ['appended to signed with a rewritten page tree', 'invalid', ['Signature1' => 'valid', 'Signature2' => 'invalid']];
        yield 'DSS and content changed after the last signature' => ['appended to signed with a DSS and a content change', 'invalid', ['Signature1' => 'valid', 'Signature2' => 'invalid']];
        yield 'document information added after the last signature' => ['appended to signed with a document information dictionary', 'inconclusive', ['Signature1' => 'valid', 'Signature2' => 'inconclusive']];
        yield 'document information switched to an object the output already holds' => ['appended to signed with an information dictionary it already holds', 'inconclusive', ['Signature1' => 'valid', 'Signature2' => 'inconclusive']];
        yield 'catalog changed and restored in two unsigned revisions' => ['appended to signed with a catalog change undone', 'invalid', ['Signature1' => 'valid', 'Signature2' => 'invalid']];
        yield 'bytes after a DSS revision before the next signature' => ['signature after a DSS revision followed by bytes', 'invalid', ['Signature1' => 'invalid', 'Signature2' => 'valid']];
        yield 'shifted gap followed by a document information dictionary' => ['appended with a shifted gap and a document information dictionary', 'invalid', ['Signature1' => 'valid', 'Signature2' => 'invalid']];
        yield 'orphan object added after the last signature' => ['appended to signed with an orphan object', 'inconclusive', ['Signature1' => 'valid', 'Signature2' => 'inconclusive']];
        yield 'bytes after the last revision' => ['appended with bytes after the range', 'invalid', ['Signature1' => 'valid', 'Signature2' => 'invalid']];
        yield 'bytes between the revisions' => ['appended to signed with bytes between the revisions', 'invalid', ['Signature1' => 'invalid', 'Signature2' => 'valid']];
        yield 'earlier range short of its revision' => ['appended to signed with a first range short of its revision', 'invalid', ['Signature1' => 'invalid', 'Signature2' => 'valid']];
        yield 'earlier range over the next revision' => ['appended to signed with a first range over the next revision', 'invalid', ['Signature1' => 'invalid', 'Signature2' => 'valid']];
        yield 'earlier range over a DSS revision' => ['signature after a DSS revision with a first range over it', 'invalid', ['Signature1' => 'invalid', 'Signature2' => 'valid']];
    }

    /**
     * @param  array<string, string>  $outcomes
     */
    #[DataProvider('outputsAddingSeveralSignatures')]
    public function test_each_added_signature_covers_its_own_revision(string $output, string $verdict, array $outcomes): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('unsigned', $output));

        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));
        self::assertSame($verdict, $observation['verdict'], $observation['evidence']['summary']);
        self::assertSame($outcomes, array_column($observation['evidence']['details']['signatures'], 'outcome', 'field'));
    }

    public function test_object_stream_rewritten_after_the_last_signature_changes_its_members(): void
    {
        $validator = self::validator(SignatureCoverageValidator::class);
        $input = self::$fixtures['object stream input'];
        $subject = static fn (string $output): ValidationSubject => new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($input), self::$fixtures[$output], $input);

        $signedTwice = $validator->observe($subject('object stream signed twice'));
        self::assertSame('valid', $signedTwice['verdict'], $signedTwice['evidence']['summary']);
        $observation = $validator->observe($subject('object stream signed twice and rewritten'));
        self::assertSame('invalid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertStringContainsString('change 2 0 R, which the document uses outside the DSS', $observation['evidence']['summary']);
    }

    public function test_document_information_the_dss_also_refers_to_is_not_exempt(): void
    {
        $input = SyntheticSignedPdf::appendRevision(self::$fixtures['signed'], self::$directory, 'signed-information-in-dss', [1 => self::SIGNED_CATALOG_START.' /DSS 9 0 R >>', 9 => '<< /Type /DSS /VRI << /ABCDEF 11 0 R >> >>', 11 => '<< /Producer (First) >>'], 12, ' /Info 11 0 R');
        $signedOnce = SyntheticSignedPdf::appendSignature($input, self::$directory, 'information-in-dss-signed-once', trailerSize: 14, firstObject: 12, trailerEntries: ' /Info 11 0 R', catalogEntries: ' /DSS 9 0 R');
        $signedTwice = SyntheticSignedPdf::appendSignature($signedOnce, self::$directory, 'information-in-dss-signed-twice', trailerSize: 16, firstObject: 14, trailerEntries: ' /Info 11 0 R', catalogEntries: ' /DSS 9 0 R');
        $output = SyntheticSignedPdf::appendRevision($signedTwice, self::$directory, 'information-in-dss-changed', [11 => '<< /Producer (Second) >>'], 16, ' /Info 11 0 R');

        $observation = self::validator(SignatureCoverageValidator::class)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe($input), $output, $input));

        self::assertSame('inconclusive', $observation['verdict'], $observation['evidence']['summary']);
        self::assertStringContainsString('change 11 0 R of the document information dictionary', $observation['evidence']['summary']);
    }

    public function test_signature_whose_range_ends_before_the_revision_of_its_dictionary_is_invalid(): void
    {
        $contents = str_repeat('ab', 64);
        $withString = SyntheticSignedPdf::appendRevision(self::$fixtures['signed'], self::$directory, 'signed-with-matching-string', [9 => "<< /Data <{$contents}> >>"], 10);
        $document = (string) file_get_contents($withString);
        $gapStart = (int) strpos($document, "<{$contents}>");
        $gapEnd = $gapStart + strlen($contents) + 2;
        $output = SyntheticSignedPdf::appendRevision($withString, self::$directory, 'signature-dictionary-after-its-range', [10 => sprintf('<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 %d %d %d] /Contents <%s> >>', $gapStart, $gapEnd, strlen($document) - $gapEnd, $contents)], 11);

        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subjectOf('unsigned', $output));

        self::assertSame('invalid', $observation['verdict'], $observation['evidence']['summary']);
        self::assertStringContainsString('10 0 R: the byte range ends at the end of the revision ending at byte '.strlen($document).', which does not hold its own dictionary', $observation['evidence']['summary']);
    }

    public function test_coverage_names_the_revision_each_range_ends_at(): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('unsigned', 'appended to signed with a DSS revision'));

        $signedSize = filesize(self::$fixtures['signed']);
        $appendedSize = filesize(self::$fixtures['appended to signed']);
        self::assertSame([['Signature1', $signedSize], ['Signature2', $appendedSize]], array_map(
            static fn (array $signature): array => [$signature['field'], $signature['revision_end']],
            $observation['evidence']['details']['signatures'],
        ));
        self::assertSame("Signature1 covers every byte of the revision ending at byte {$signedSize} except its /Contents, Signature2 covers every byte of the revision ending at byte {$appendedSize} except its /Contents", $observation['evidence']['summary']);
        self::assertSame([['after_revision_end' => $appendedSize, 'revision_ends' => [filesize(self::$fixtures['appended to signed with a DSS revision'])], 'outcome' => 'valid', 'changed_objects' => ['1 0 R', '9 0 R', '10 0 R'], 'problems' => []]], $observation['evidence']['details']['unsigned_revisions']);
    }

    public static function severalSignatureProblems(): iterable
    {
        yield 'bytes between the revisions' => ['appended to signed with bytes between the revisions', 'Signature1: the byte range ends at byte {signed size}, which is not the end of a revision followed by an incremental update or by the end of the file'];
        yield 'earlier range short of its revision' => ['appended to signed with a first range short of its revision', 'Signature1: the byte range ends at byte {signed size - 2}, which is not the end of a revision followed by an incremental update or by the end of the file'];
        yield 'earlier range over the next revision' => ['appended to signed with a first range over the next revision', 'Signature1: the byte range ends at the end of the revision ending at byte {appended size}, but its own dictionary is in an earlier revision'];
        yield 'content changed after the last signature' => ['appended to signed with a content change', 'Signature2: the revisions after its own up to byte {content change size}, which add no signature, change 4 0 R, which the document uses outside the DSS'];
        yield 'document information added after the last signature' => ['appended to signed with a document information dictionary', 'Signature2: the revisions after its own up to byte {information size}, which add no signature, change the /Info of the trailer, which ETSI EN 319 142-1 does not exempt, change 9 0 R of the document information dictionary, which ETSI EN 319 142-1 does not exempt'];
    }

    #[DataProvider('severalSignatureProblems')]
    public function test_coverage_names_the_revision_problem(string $output, string $summary): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('unsigned', $output));

        $signedSize = (int) filesize(self::$fixtures['signed']);
        self::assertSame(strtr($summary, [
            '{signed size}' => (string) $signedSize,
            '{signed size - 2}' => (string) ($signedSize - 2),
            '{appended size}' => (string) filesize(self::$fixtures['appended to signed']),
            '{content change size}' => (string) filesize(self::$fixtures['appended to signed with a content change']),
            '{information size}' => (string) filesize(self::$fixtures['appended to signed with a document information dictionary']),
        ]), $observation['evidence']['summary']);
    }

    public function test_single_added_signature_must_still_reach_the_end_of_the_output(): void
    {
        $observation = self::validator(SignatureCoverageValidator::class)->observe(self::subject('signed', 'appended to signed with a DSS revision'));

        $uncovered = filesize(self::$fixtures['appended to signed with a DSS revision']) - filesize(self::$fixtures['appended to signed']);
        self::assertSame('invalid', $observation['verdict']);
        self::assertSame("Signature2: the byte range does not reach the end of the file, leaving {$uncovered} bytes uncovered", $observation['evidence']['summary']);
        self::assertArrayNotHasKey('revision_end', $observation['evidence']['details']['signatures'][0]);
    }

    public function test_bytes_after_the_last_revision_stay_invalid_when_qpdf_does_not_give_the_contents(): void
    {
        $withoutContents = static function (CommandResult $result): CommandResult {
            $listing = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
            $listing['qpdf'][1]['obj:8 0 R']['value']['/Contents'] = 'u:é';

            return new CommandResult($result->exitCode, json_encode($listing, JSON_THROW_ON_ERROR));
        };
        $validator = self::alteredValidator(SignatureCoverageValidator::class, self::$fixtures['appended with bytes after the range'], $withoutContents);

        $observation = $validator->observe(self::subject('unsigned', 'appended with bytes after the range'));

        self::assertSame('invalid', $observation['verdict'], $observation['evidence']['summary']);
    }

    public static function priorSignatureProblems(): iterable
    {
        yield 'signed bytes changed' => ['appended to tampered', 'Signature1: the bytes its range covers changed'];
        yield 'removed from the fields' => ['appended without the prior field', 'Signature1: it is no longer the value of the field Signature1'];
    }

    #[DataProvider('priorSignatureProblems')]
    public function test_prior_signatures_report_each_signature_of_the_input(string $output, string $summary): void
    {
        $observation = self::validator(PriorSignaturesValidator::class)->observe(self::subject('signed', $output));

        self::assertSame($summary, $observation['evidence']['summary']);
        self::assertSame([['object' => '6 0 R', 'field' => 'Signature1', 'outcome' => 'broken']], array_map(
            static fn (array $signature): array => array_intersect_key($signature, ['object' => true, 'field' => true, 'outcome' => true]),
            $observation['evidence']['details']['signatures'],
        ));
    }

    public function test_preservation_reports_where_the_output_departs_from_the_input(): void
    {
        $observation = self::validator(InputPreservationValidator::class)->observe(self::subject('leading bytes', 'appended to unsigned'));

        self::assertSame(1, $observation['evidence']['exit_code']);
        self::assertStringStartsWith('The output does not start with the bytes of the input: ', $observation['evidence']['summary']);
        self::assertStringContainsString('differ: char 1, line 1', $observation['evidence']['raw_output']);
        self::assertSame(['compared_bytes' => filesize(self::$fixtures['leading bytes'])], $observation['evidence']['details']);
    }

    /**
     * @param  class-string<ComparisonValidator>  $validatorClass
     */
    #[DataProvider('validators')]
    public function test_attempt_without_output_is_not_evaluated(string $validatorClass): void
    {
        $observation = self::validator($validatorClass)->observe(self::subject('signed', null));

        self::assertSame([], (new SchemaValidator)->errors($observation, ToolValidator::OBSERVATION_SCHEMA));
        self::assertSame('not_evaluated', $observation['verdict']);
    }

    /**
     * @param  class-string<ComparisonValidator>  $validatorClass
     */
    #[DataProvider('validators')]
    public function test_input_that_differs_from_its_record_is_refused(string $validatorClass): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the input artifact');

        self::validator($validatorClass)->observe(new ValidationSubject(self::ATTEMPT_ID, Artifact::describe(self::$fixtures['signed']), self::$fixtures['appended to signed'], self::$fixtures['unsigned']));
    }

    public static function validators(): iterable
    {
        foreach (self::VALIDATORS as $validatorClass) {
            yield self::validator($validatorClass)->analyzerId() => [$validatorClass];
        }
    }

    public function test_panel_gives_a_baseline_only_for_analyzers_that_judge_one_document(): void
    {
        $panel = new ValidationPanel([self::validator(QpdfCheckValidator::class), ...array_map(self::validator(...), self::VALIDATORS)]);
        $inputPath = self::$fixtures['signed'];

        $baselines = $panel->baseline(new BaselineSubject('run-20261107T120000Z', 'synthetic:signed.pdf', Artifact::describe($inputPath), $inputPath));
        $observations = $panel->observe(self::subject('signed', 'appended to signed'));

        self::assertSame(['qpdf'], array_map(static fn (array $baseline): string => $baseline['analyzer']['id'], $baselines));
        self::assertSame(['qpdf', 'cmp', 'qpdf-prior-signatures', 'qpdf-byte-range'], array_map(static fn (array $observation): string => $observation['analyzer']['id'], $observations));
    }
}
