<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptResult;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\JSignPdfImplementation;
use SignerPHP\PdfSigningQualification\Signing\NetworkIsolation;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;
use SignerPHP\PdfSigningQualification\Signing\SigningRequest;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticSignedPdf;
use SignerPHP\PdfSigningQualification\Validation\InputPreservationValidator;
use SignerPHP\PdfSigningQualification\Validation\OpenSslCmsValidator;
use SignerPHP\PdfSigningQualification\Validation\PdfsigValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\PyHankoValidator;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\SignatureCoverageValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class JSignPdfImplementationTest extends TestCase
{
    private const string RUN_ID = 'run-20261106T120000Z';

    private const string SCENARIO_PATH = __DIR__.'/../../config/scenarios/basic-invisible-sha256.json';

    private const string SANDBOX_DIRECTORY = '/sandbox';

    private const string MAIN_CLASS = 'com.intoolswetrust.jsignpdf.Bootstrap';

    private static string $directory;

    private static TestCredential $credential;

    private static string $unsignedPath;

    private string $workDirectory;

    private string $outputDirectory;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/jsignpdf-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        mkdir(self::$directory.'/credential');
        self::$credential = TestCredential::generate(self::$directory.'/credential');
        self::$unsignedPath = self::$directory.'/unsigned-1.7.pdf';
        file_put_contents(self::$unsignedPath, self::pdfOfVersion('1.7'));
    }

    public static function tearDownAfterClass(): void
    {
        self::$credential->remove();
        exec('rm -rf '.escapeshellarg(self::$directory));
    }

    protected function setUp(): void
    {
        $this->workDirectory = self::SANDBOX_DIRECTORY.'/work-'.bin2hex(random_bytes(4));
        $this->outputDirectory = self::$directory.'/outputs-'.bin2hex(random_bytes(4));
        mkdir($this->workDirectory);
    }

    protected function tearDown(): void
    {
        exec('chmod -R u+rwx '.escapeshellarg($this->workDirectory).' 2>/dev/null; rm -rf '.escapeshellarg($this->workDirectory));
    }

    private static function pdfOfVersion(string $version): string
    {
        $unsignedPath = SyntheticSignedPdf::createUnsigned(self::$directory);
        $document = (string) file_get_contents($unsignedPath);

        return (string) preg_replace('/\A%PDF-\d\.\d/', "%PDF-{$version}", $document);
    }

    private function runner(?ResourceLimits $limits = null): AttemptRunner
    {
        return new AttemptRunner(
            new ProcessRunner,
            new NetworkIsolation,
            self::$credential,
            $this->workDirectory,
            $this->outputDirectory,
            $limits ?? new ResourceLimits,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-06T12:00:01Z'),
        );
    }

    private static function scenario(): array
    {
        $scenario = json_decode((string) file_get_contents(self::SCENARIO_PATH), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($scenario);

        return $scenario;
    }

    private static function availableAcquisition(string $path): array
    {
        return [
            'schema' => SchemaValidator::SCHEMA_PREFIX.'v1/acquisition.schema.json',
            'source_id' => 'synthetic:unsigned.pdf',
            'source' => [
                'id' => 'synthetic',
                'url' => 'https://github.com/SignerPHP/pdf-signing-qualification',
                'revision' => ['kind' => 'version', 'value' => '1.0.0'],
                'license' => ['expression' => 'MIT', 'evidence_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/LICENSE'],
            ],
            'path' => 'unsigned.pdf',
            'file_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/unsigned.pdf',
            'status' => 'available',
            'retrieved_at' => '2026-11-06T12:00:00Z',
            'sha256' => (string) hash_file('sha256', $path),
            'byte_size' => (int) filesize($path),
            'license' => ['expression' => 'MIT', 'evidence_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/LICENSE'],
        ];
    }

    private function sign(string $inputPath, ?ResourceLimits $limits = null, ?array $scenario = null): AttemptResult
    {
        return $this->runner($limits)->run(self::RUN_ID, $scenario ?? self::scenario(), new JSignPdfImplementation, self::availableAcquisition($inputPath), $inputPath);
    }

    /**
     * @return list<int>
     */
    private static function runningJvmProcessIds(): array
    {
        $processIds = [];
        foreach ((array) glob('/proc/[0-9]*/cmdline') as $path) {
            $commandLine = @file_get_contents((string) $path);
            if (is_string($commandLine) && str_contains($commandLine, self::MAIN_CLASS)) {
                $processIds[] = (int) basename(dirname((string) $path));
            }
        }

        return $processIds;
    }

    private static function assertOutputHoldsOnlyTheSignatureOfTheScenario(string $outputPath): void
    {
        $document = json_decode((string) shell_exec('qpdf --json=2 --json-key=qpdf '.escapeshellarg($outputPath)), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $objects = array_map(static fn (array $object): mixed => $object['value'] ?? null, $document['qpdf'][1]);

        $signatures = array_values(array_filter($objects, static fn (mixed $value): bool => is_array($value) && isset($value['/ByteRange'])));
        self::assertCount(1, $signatures);
        self::assertSame('/adbe.pkcs7.detached', $signatures[0]['/SubFilter']);

        $widgets = array_values(array_filter($objects, static fn (mixed $value): bool => is_array($value) && ($value['/Subtype'] ?? null) === '/Widget' && ($value['/FT'] ?? null) === '/Sig'));
        self::assertCount(1, $widgets);
        self::assertSame([0, 0, 0, 0], $widgets[0]['/Rect']);

        foreach ($objects as $value) {
            self::assertFalse(is_array($value) && isset($value['/DSS']), 'The output holds a document security store');
        }

        $contentsPath = self::$directory.'/contents-'.bin2hex(random_bytes(4)).'.der';
        file_put_contents($contentsPath, (string) hex2bin(substr((string) $signatures[0]['/Contents'], 2)));
        $cmsStructure = (string) shell_exec('openssl cms -cmsout -inform DER -in '.escapeshellarg($contentsPath).' -print 2>&1');
        unlink($contentsPath);

        self::assertMatchesRegularExpression('/digestAlgorithm:\s+algorithm: sha256 /', $cmsStructure);
        self::assertMatchesRegularExpression('/signatureAlgorithm:\s+algorithm: rsaEncryption /', $cmsStructure);
        self::assertStringNotContainsString('rsassaPss', $cmsStructure);
        self::assertStringNotContainsString('1.2.840.113549.1.9.16.2.14', $cmsStructure);
        self::assertStringNotContainsString('1.2.840.113583.1.1.8', $cmsStructure);
    }

    public function test_revision_is_the_exact_installed_version(): void
    {
        self::assertSame(['kind' => 'version', 'value' => '3.2.1'], (new JSignPdfImplementation)->revision());
    }

    public function test_jsignpdf_appends_a_signature_of_the_scenario_that_passes_every_validator(): void
    {
        $result = $this->sign(self::$unsignedPath);

        $record = $result->record;
        self::assertSame('succeeded', $record['execution']['outcome'], (string) ($record['execution']['error']['message'] ?? ''));
        self::assertSame(['id' => 'jsignpdf', 'revision' => ['kind' => 'version', 'value' => '3.2.1']], $record['implementation']);
        self::assertSame(self::RUN_ID.'/basic-invisible-sha256/jsignpdf/synthetic:unsigned.pdf', $record['id']);
        self::assertNotNull($result->outputPath);
        self::assertStringNotContainsString(self::$credential->password, json_encode($record, JSON_THROW_ON_ERROR));

        $input = (string) file_get_contents(self::$unsignedPath);
        self::assertStringStartsWith($input, (string) file_get_contents($result->outputPath));

        self::assertOutputHoldsOnlyTheSignatureOfTheScenario($result->outputPath);

        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-06T12:00:02Z');
        $panel = new ValidationPanel([
            new PyHankoValidator(new ProcessRunner, $clock),
            new PdfsigValidator(new ProcessRunner, $clock),
            new OpenSslCmsValidator(new ProcessRunner, $clock),
            new QpdfCheckValidator(new ProcessRunner, $clock),
            new InputPreservationValidator(new ProcessRunner, $clock),
            new SignatureCoverageValidator(new ProcessRunner, $clock),
        ]);
        $inputArtifact = ['sha256' => $record['input']['sha256'], 'byte_size' => $record['input']['byte_size']];
        foreach ($panel->observe(new ValidationSubject($record['id'], $inputArtifact, $result->outputPath, self::$unsignedPath)) as $observation) {
            self::assertSame('valid', $observation['verdict'], $observation['analyzer']['id'].': '.$observation['evidence']['raw_output']);
        }
    }

    public function test_pdf_older_than_1_6_is_signed_by_an_incremental_update_that_keeps_its_declared_version(): void
    {
        $olderPath = self::$directory.'/unsigned-1.4.pdf';
        file_put_contents($olderPath, self::pdfOfVersion('1.4'));

        $result = $this->sign($olderPath);

        self::assertSame('succeeded', $result->record['execution']['outcome'], (string) ($result->record['execution']['error']['message'] ?? ''));
        self::assertNotNull($result->outputPath);
        $signedDocument = (string) file_get_contents($result->outputPath);
        self::assertStringStartsWith((string) file_get_contents($olderPath), $signedDocument);
        self::assertStringStartsWith('%PDF-1.4', $signedDocument);
        self::assertStringContainsString('Signing Hash Algorithm: SHA-256', (string) shell_exec('pdfsig -nocert '.escapeshellarg($result->outputPath).' 2>&1'));
    }

    public function test_bytes_before_the_header_are_dropped_and_the_rest_is_kept_byte_for_byte(): void
    {
        $leadingBytes = "leading junk\n\n";
        $document = (string) file_get_contents(self::$unsignedPath);
        $inputPath = self::$directory.'/leading-bytes.pdf';
        file_put_contents($inputPath, $leadingBytes.$document);

        $result = $this->sign($inputPath);

        self::assertSame('succeeded', $result->record['execution']['outcome'], (string) ($result->record['execution']['error']['message'] ?? ''));
        self::assertNotNull($result->outputPath);
        self::assertStringStartsWith($document, (string) file_get_contents($result->outputPath));

        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-07T12:00:02Z');
        $subject = new ValidationSubject($result->record['id'], Artifact::describe($inputPath), $result->outputPath, $inputPath);
        $panel = new ValidationPanel([
            new PdfsigValidator(new ProcessRunner, $clock),
            new InputPreservationValidator(new ProcessRunner, $clock),
            new SignatureCoverageValidator(new ProcessRunner, $clock),
        ]);
        self::assertSame(
            ['pdfsig' => 'valid', 'cmp' => 'invalid', 'qpdf-byte-range' => 'valid'],
            array_column(array_map(static fn (array $observation): array => ['analyzer' => $observation['analyzer']['id'], 'verdict' => $observation['verdict']], $panel->observe($subject)), 'verdict', 'analyzer'),
        );
    }

    public function test_jsignpdf_receives_the_scenario_options_and_never_rewrites_the_document(): void
    {
        $attemptDirectory = $this->workDirectory.'/attempt';
        mkdir($attemptDirectory);
        $implementation = new JSignPdfImplementation(javaBinary: __DIR__.'/recording-java');
        $request = new SigningRequest(self::$unsignedPath, $attemptDirectory.'/output.pdf', self::$credential, self::scenario()['signing']);

        (new ProcessRunner)->run($implementation->command($request), 30.0);

        $arguments = (array) file($attemptDirectory.'/java-arguments', FILE_IGNORE_NEW_LINES);
        self::assertNotContains('--overwrite', $arguments);
        self::assertContains('-a', $arguments);
        self::assertSame('SHA256', $arguments[array_search('-ha', $arguments, true) + 1]);
        self::assertSame('openpdf', $arguments[array_search('-eng', $arguments, true) + 1]);
        self::assertContains('-Xmx512m', $arguments);
        self::assertContains('-Djava.io.tmpdir='.$attemptDirectory, $arguments);
        self::assertContains('-Duser.home='.$attemptDirectory, $arguments);
    }

    public function test_runtime_reports_what_a_reader_needs_to_repeat_the_run(): void
    {
        self::assertSame([
            'java_version' => '21.0.13+9-LTS',
            'java_options' => ['-Xmx512m', '-XX:+UseSerialGC', '-XX:CompressedClassSpaceSize=64m', '-XX:ReservedCodeCacheSize=48m', '-XX:-UsePerfData'],
            'environment_variables' => ['MALLOC_ARENA_MAX=1'],
            'engine' => 'openpdf',
            'wrapper' => ['id' => 'jsignpdf-php', 'revision' => ['kind' => 'commit', 'value' => '6f150373a41cd2f149725e428e21d3f9ca5b4267']],
        ], (new JSignPdfImplementation)->runtime());
    }

    public function test_malformed_input_is_a_failed_attempt(): void
    {
        $malformedPath = self::$directory.'/malformed.pdf';
        file_put_contents($malformedPath, "%PDF-1.7\nthis is not a PDF body\n");

        $result = $this->sign($malformedPath);

        self::assertSame('failed', $result->record['execution']['outcome']);
        self::assertNull($result->outputPath);
    }

    public function test_attempt_exceeding_the_time_limit_is_timed_out_and_the_jvm_is_killed(): void
    {
        $runningBefore = self::runningJvmProcessIds();

        $result = $this->sign(self::$unsignedPath, new ResourceLimits(timeoutSeconds: 0.7));

        self::assertSame('timed_out', $result->record['execution']['outcome']);
        self::assertSame('supported', $result->record['capability']);
        self::assertNull($result->record['execution']['exit_code']);
        self::assertNull($result->outputPath);
        self::assertSame($runningBefore, self::runningJvmProcessIds());
    }

    public function test_attempt_writes_nothing_outside_its_work_directory(): void
    {
        $temporaryFilesBefore = (array) scandir(sys_get_temp_dir());

        $result = $this->sign(self::$unsignedPath);

        self::assertSame('succeeded', $result->record['execution']['outcome'], (string) ($result->record['execution']['error']['message'] ?? ''));
        self::assertSame([], array_values(array_diff((array) scandir(sys_get_temp_dir()), $temporaryFilesBefore)));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function unconfirmedSigning(): iterable
    {
        yield 'another digest' => ['digest_algorithm', 'sha512'];
        yield 'another signature algorithm' => ['signature_algorithm', 'rsa-pss'];
        yield 'CAdES sub filter' => ['sub_filter', 'ETSI.CAdES.detached'];
        yield 'visible appearance' => ['appearance', 'visible'];
        yield 'timestamp' => ['timestamp', true];
        yield 'revocation information' => ['revocation_info', true];
    }

    #[DataProvider('unconfirmedSigning')]
    public function test_signing_parameters_not_confirmed_from_outputs_are_not_supported(string $parameter, mixed $value): void
    {
        $signing = self::scenario()['signing'];
        $signing[$parameter] = $value;

        self::assertFalse((new JSignPdfImplementation)->supports($signing));
    }

    public function test_scenario_confirmed_from_outputs_is_supported(): void
    {
        self::assertTrue((new JSignPdfImplementation)->supports(self::scenario()['signing']));
    }

    public function test_unsupported_scenario_is_not_run(): void
    {
        $scenario = self::scenario();
        $scenario['signing']['digest_algorithm'] = 'sha512';

        $result = $this->sign(self::$unsignedPath, scenario: $scenario);

        self::assertSame('unsupported', $result->record['capability']);
        self::assertSame('not_run', $result->record['execution']['outcome']);
        self::assertNull($result->outputPath);
    }
}
