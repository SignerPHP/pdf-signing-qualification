<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Validation\BaselineSubject;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\ComparisonValidator;
use SignerPHP\PdfSigningQualification\Validation\InputPreservationValidator;
use SignerPHP\PdfSigningQualification\Validation\OpenSslCmsValidator;
use SignerPHP\PdfSigningQualification\Validation\PdfsigValidator;
use SignerPHP\PdfSigningQualification\Validation\PriorSignaturesValidator;
use SignerPHP\PdfSigningQualification\Validation\PyHankoValidator;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\SignatureCoverageValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class UnexpectedToolOutcomeTest extends TestCase
{
    private const array INPUT_ARTIFACT = ['sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9', 'byte_size' => 1_024];

    private static string $directory;

    private static string $signedPath;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/unexpected-tool-outcome-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        self::$signedPath = SyntheticSignedPdf::create(self::$directory);
    }

    public static function tearDownAfterClass(): void
    {
        array_map(unlink(...), glob(self::$directory.'/*') ?: []);
        rmdir(self::$directory);
    }

    public static function toolVersions(): iterable
    {
        yield 'pyHanko' => [PyHankoValidator::class, "pyHanko, version 0.38.0 (CLI 0.6.0)\n"];
        yield 'pdfsig' => [PdfsigValidator::class, "pdfsig version 22.12.0\nCopyright 2005-2022 The Poppler Developers\n"];
        yield 'openssl' => [OpenSslCmsValidator::class, "OpenSSL 3.0.22 25 Aug 2026 (Library: OpenSSL 3.0.22 25 Aug 2026)\n"];
        yield 'qpdf' => [QpdfCheckValidator::class, "qpdf version 11.3.0\n"];
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    private static function observe(string $validatorClass, string $versionOutput, CommandResult $validationResult): array
    {
        $runner = new FakeCommandRunner(new CommandResult(0, $versionOutput), $validationResult);
        $validator = new $validatorClass($runner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-29T12:00:03Z'));

        return $validator->observe(new ValidationSubject('run-20261029T120000Z/synthetic/1', self::INPUT_ARTIFACT, self::$signedPath));
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    private static function baseline(string $validatorClass, string $versionOutput, CommandResult $validationResult): array
    {
        $runner = new FakeCommandRunner(new CommandResult(0, $versionOutput), $validationResult);
        $validator = new $validatorClass($runner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-29T12:00:03Z'));

        return $validator->baseline(new BaselineSubject('run-20261029T120000Z', 'synthetic:input.pdf', self::INPUT_ARTIFACT, self::$signedPath));
    }

    /**
     * @return iterable<string, array{class-string<CommandLineValidator>, string, CommandResult, string}>
     */
    public static function noSignatureReports(): iterable
    {
        $noByteRange = (string) json_encode(['qpdf' => [[], ['obj:1 0 R' => ['value' => ['/Type' => '/Catalog']]]]]);

        yield 'pdfsig on a readable document' => [PdfsigValidator::class, "pdfsig version 22.12.0\n", new CommandResult(2, "File 'input.pdf' does not contain any signatures\n"), 'not_applicable'];
        yield 'pdfsig after a syntax error' => [PdfsigValidator::class, "pdfsig version 22.12.0\n", new CommandResult(2, "Syntax Error (2): Unknown compression method in flate stream\nFile 'input.pdf' does not contain any signatures\n"), 'inconclusive'];
        yield 'pdfsig after an internal error' => [PdfsigValidator::class, "pdfsig version 22.12.0\n", new CommandResult(2, "Internal Error: xref num 3 not found but needed\nFile 'input.pdf' does not contain any signatures\n"), 'inconclusive'];
        yield 'qpdf listing without warnings' => [OpenSslCmsValidator::class, "OpenSSL 3.0.22 25 Aug 2026\n", new CommandResult(0, $noByteRange), 'not_applicable'];
        yield 'qpdf listing of a recovered file' => [OpenSslCmsValidator::class, "OpenSSL 3.0.22 25 Aug 2026\n", new CommandResult(3, $noByteRange), 'inconclusive'];
        yield 'pyHanko that finished without a signature' => [PyHankoValidator::class, "pyHanko, version 0.38.0 (CLI 0.6.0)\n", new CommandResult(0, ''), 'not_applicable'];
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('noSignatureReports')]
    public function test_no_signature_is_not_applicable_to_an_input_only_when_the_tool_read_it_and_stays_invalid_on_an_output(string $validatorClass, string $versionOutput, CommandResult $validationResult, string $inputVerdict): void
    {
        $baseline = self::baseline($validatorClass, $versionOutput, $validationResult);
        $observation = self::observe($validatorClass, $versionOutput, $validationResult);

        self::assertSame($inputVerdict, $baseline['verdict']);
        self::assertSame('invalid', $observation['verdict']);
        self::assertSame($validationResult->exitCode, $baseline['evidence']['exit_code']);
    }

    public function test_failed_pyhanko_validation_without_a_signature_line_is_not_read_as_no_signature(): void
    {
        $result = new CommandResult(1, "unparsed signature report\nError: Validation failed\n");

        $baseline = self::baseline(PyHankoValidator::class, "pyHanko, version 0.38.0 (CLI 0.6.0)\n", $result);
        $observation = self::observe(PyHankoValidator::class, "pyHanko, version 0.38.0 (CLI 0.6.0)\n", $result);

        self::assertSame('inconclusive', $baseline['verdict']);
        self::assertSame('inconclusive', $observation['verdict']);
        self::assertStringNotContainsString('found no signature', $baseline['evidence']['summary']);
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('toolVersions')]
    public function test_timed_out_tool_is_inconclusive(string $validatorClass, string $versionOutput): void
    {
        $observation = self::observe($validatorClass, $versionOutput, new CommandResult(null, 'partial output', true));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertNull($observation['evidence']['exit_code']);
        self::assertStringContainsString('partial output', $observation['evidence']['raw_output']);
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('toolVersions')]
    public function test_tool_crash_is_not_read_as_a_valid_signature(string $validatorClass, string $versionOutput): void
    {
        $observation = self::observe($validatorClass, $versionOutput, new CommandResult(139, 'Segmentation fault'));

        self::assertNotSame('valid', $observation['verdict']);
        self::assertSame(139, $observation['evidence']['exit_code']);
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('toolVersions')]
    public function test_analyzer_version_is_the_one_reported_by_the_tool(string $validatorClass, string $versionOutput): void
    {
        $observation = self::observe($validatorClass, $versionOutput, new CommandResult(0, ''));

        self::assertSame(['kind' => 'version', 'value' => preg_replace('/^\D*?(\d+\.\d+\.\d+).*$/s', '$1', $versionOutput)], $observation['analyzer']['revision']);
    }

    /**
     * @param  class-string<CommandLineValidator>  $validatorClass
     */
    #[DataProvider('toolVersions')]
    public function test_unknown_tool_version_stops_the_observation(string $validatorClass): void
    {
        $this->expectException(RuntimeException::class);

        self::observe($validatorClass, 'unexpected banner', new CommandResult(0, ''));
    }

    public function test_pdfsig_without_a_decided_signature_is_inconclusive(): void
    {
        $observation = self::observe(PdfsigValidator::class, "pdfsig version 22.12.0\n", new CommandResult(0, "Digital Signature Info of: signed.pdf\nSignature #1:\n  - Signature Field Name: Signature1\n  - Signature Validation: Signature has not yet been verified.\n"));

        self::assertSame('inconclusive', $observation['verdict']);
    }

    public function test_pyhanko_failure_before_the_report_is_inconclusive(): void
    {
        $observation = self::observe(PyHankoValidator::class, "pyHanko, version 0.38.0 (CLI 0.6.0)\n", new CommandResult(1, "Error: Failed to read PDF file: startxref not found\n"));

        self::assertSame('inconclusive', $observation['verdict']);
    }

    public function test_pyhanko_error_other_than_the_hybrid_reference_refusal_stays_invalid(): void
    {
        $output = "2026-10-29 12:00:03,000 - cli - ERROR - An error occurred while validating this signature: Signature algorithm sha1_rsa is not allowed.\n"
            .'Signature1:'.str_repeat('a', 64).":INVALID\nError: Validation failed\n";

        $observation = self::observe(PyHankoValidator::class, "pyHanko, version 0.38.0 (CLI 0.6.0)\n", new CommandResult(1, $output));

        self::assertSame('invalid', $observation['verdict']);
        self::assertSame('Signature algorithm sha1_rsa is not allowed.', $observation['evidence']['details']['signatures'][0]['error']);
    }

    private const string PYHANKO_VERSION = "pyHanko, version 0.38.0 (CLI 0.6.0)\n";

    private const string HYBRID_REFERENCE_REFUSAL = '2026-10-29 12:00:03,000 - cli - ERROR - An error occurred while validating this signature: Settings do not permit validation of signatures in hybrid-reference files.';

    private static function pyHankoStatus(string $field, string $status): string
    {
        return $field.':'.str_repeat('a', 64).':'.$status;
    }

    public function test_pyhanko_signature_judged_invalid_beside_a_refused_one_of_the_same_name_is_invalid(): void
    {
        $output = implode("\n", [self::HYBRID_REFERENCE_REFUSAL, self::pyHankoStatus('None', 'INVALID'), self::pyHankoStatus('None', 'INVALID'), 'Error: Validation failed', '']);

        $observation = self::observe(PyHankoValidator::class, self::PYHANKO_VERSION, new CommandResult(1, $output));

        self::assertSame('invalid', $observation['verdict']);
        self::assertSame('pyHanko judged the signature(s) None not intact or not cryptographically valid', $observation['evidence']['summary']);
    }

    public function test_pyhanko_refusal_of_every_signature_is_inconclusive(): void
    {
        $output = implode("\n", [self::HYBRID_REFERENCE_REFUSAL, self::pyHankoStatus('Signature1', 'INVALID'), self::HYBRID_REFERENCE_REFUSAL, self::pyHankoStatus('Signature2', 'INVALID'), 'Error: Validation failed', '']);

        $observation = self::observe(PyHankoValidator::class, self::PYHANKO_VERSION, new CommandResult(1, $output));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame('pyHanko refused to judge the signature(s) Signature1, Signature2 under its strict syntax policy: Settings do not permit validation of signatures in hybrid-reference files.', $observation['evidence']['summary']);
    }

    public function test_pyhanko_field_name_quoting_the_refusal_stays_a_signature(): void
    {
        $output = implode("\n", [
            self::pyHankoStatus("b'x\\n2026-10-31 00:00:00,000 - cli - ERROR - An error occurred while validating this signature: Settings do not permit validation of signatures in hybrid-reference files.\\ny'", 'INVALID'),
            self::pyHankoStatus('Signature2', 'INTACT:UNTRUSTED,UNTOUCHED'),
            'Error: Validation failed',
            '',
        ]);

        $observation = self::observe(PyHankoValidator::class, self::PYHANKO_VERSION, new CommandResult(1, $output));

        self::assertSame('invalid', $observation['verdict']);
        self::assertCount(2, $observation['evidence']['details']['signatures']);
        self::assertArrayNotHasKey('error', $observation['evidence']['details']['signatures'][1]);
    }

    public function test_pyhanko_error_is_attached_only_to_the_status_line_right_after_it(): void
    {
        $output = implode("\n", [self::HYBRID_REFERENCE_REFUSAL, 'unexpected line', self::pyHankoStatus('Signature1', 'INVALID'), 'Error: Validation failed', '']);

        $observation = self::observe(PyHankoValidator::class, self::PYHANKO_VERSION, new CommandResult(1, $output));

        self::assertSame('invalid', $observation['verdict']);
        self::assertArrayNotHasKey('error', $observation['evidence']['details']['signatures'][0]);
    }

    public function test_pyhanko_policy_records_which_refusal_is_inconclusive(): void
    {
        $observation = self::observe(PyHankoValidator::class, self::PYHANKO_VERSION, new CommandResult(0, self::pyHankoStatus('Signature1', 'INTACT:UNTRUSTED,UNTOUCHED')."\n"));

        self::assertSame(['Settings do not permit validation of signatures in hybrid-reference files.'], $observation['policy']['parameters']['inconclusive_refusals']);
    }

    public function test_qpdf_warnings_are_inconclusive(): void
    {
        $observation = self::observe(QpdfCheckValidator::class, "qpdf version 11.3.0\n", new CommandResult(3, "WARNING: file is damaged\nqpdf: operation succeeded with warnings\n"));

        self::assertSame('inconclusive', $observation['verdict']);
    }

    /**
     * @return iterable<string, array{class-string<ComparisonValidator>, string, CommandResult, string}>
     */
    public static function comparisonToolFailures(): iterable
    {
        $noByteRange = (string) json_encode(['qpdf' => [[], ['obj:1 0 R' => ['value' => ['/Type' => '/Catalog']]]]]);

        yield 'cmp that could not compare' => [InputPreservationValidator::class, "cmp (GNU diffutils) 3.8\n", new CommandResult(2, "cmp: output.pdf: No such file or directory\n"), 'cmp exited with code 2'];
        yield 'cmp that timed out' => [InputPreservationValidator::class, "cmp (GNU diffutils) 3.8\n", new CommandResult(null, '', timedOut: true), 'cmp did not finish within 120 seconds'];
        yield 'prior signatures of an input qpdf cannot read' => [PriorSignaturesValidator::class, "qpdf version 11.3.0\n", new CommandResult(2, "qpdf: input.pdf: can't find startxref\n"), 'qpdf could not list the signatures of the input: qpdf exited with code 2'];
        yield 'prior signatures of a recovered input without one' => [PriorSignaturesValidator::class, "qpdf version 11.3.0\n", new CommandResult(3, $noByteRange), 'No dictionary with a /ByteRange was found in an input qpdf had to recover'];
        yield 'prior signatures listing that timed out' => [PriorSignaturesValidator::class, "qpdf version 11.3.0\n", new CommandResult(null, '', timedOut: true), 'qpdf-prior-signatures did not finish within 120 seconds'];
        yield 'coverage of an input qpdf cannot read' => [SignatureCoverageValidator::class, "qpdf version 11.3.0\n", new CommandResult(2, "qpdf: input.pdf: can't find startxref\n"), 'qpdf could not list the signatures of the input, so the added signature cannot be told apart: qpdf exited with code 2'];
        yield 'coverage of a recovered output without a new signature' => [SignatureCoverageValidator::class, "qpdf version 11.3.0\n", new CommandResult(3, $noByteRange), 'No signature that is not already in the input was found in an output qpdf had to recover'];
    }

    /**
     * @param  class-string<ComparisonValidator>  $validatorClass
     */
    #[DataProvider('comparisonToolFailures')]
    public function test_comparison_that_the_tool_could_not_complete_is_inconclusive(string $validatorClass, string $versionOutput, CommandResult $toolResult, string $summary): void
    {
        $runner = new FakeCommandRunner(new CommandResult(0, $versionOutput), $toolResult);
        $validator = new $validatorClass($runner, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-07T12:00:03Z'));

        $observation = $validator->observe(new ValidationSubject('run-20261107T120000Z/synthetic/1', Artifact::describe(self::$signedPath), self::$signedPath, self::$signedPath));

        self::assertSame('inconclusive', $observation['verdict']);
        self::assertSame($summary, $observation['evidence']['summary']);
    }
}
