<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Signing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptResult;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\NetworkIsolation;
use SignerPHP\PdfSigningQualification\Signing\PdfSignerImplementation;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Signing\SigningRequest;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Tests\Validation\ProcessStatus;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticSignedPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\OpenSslCmsValidator;
use SignerPHP\PdfSigningQualification\Validation\PdfsigValidator;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\PyHankoValidator;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class AttemptRunnerTest extends TestCase
{
    private const string RUN_ID = 'run-20261029T120000Z';

    private const string SCENARIO_PATH = __DIR__.'/../../config/scenarios/basic-invisible-sha256.json';

    private const string SANDBOX_DIRECTORY = '/sandbox';

    private static string $directory;

    private static TestCredential $credential;

    private static string $unsignedPath;

    private string $workDirectory;

    private string $outputDirectory;

    public static function setUpBeforeClass(): void
    {
        self::$directory = sys_get_temp_dir().'/attempts-'.bin2hex(random_bytes(6));
        mkdir(self::$directory);
        mkdir(self::$directory.'/credential');
        self::$credential = TestCredential::generate(self::$directory.'/credential');
        self::$unsignedPath = SyntheticSignedPdf::createUnsigned(self::$directory);
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

    private function runner(?ResourceLimits $limits = null, ?NetworkIsolation $networkIsolation = null, ?CommandRunner $commandRunner = null): AttemptRunner
    {
        return new AttemptRunner(
            $commandRunner ?? new ProcessRunner,
            $networkIsolation ?? new NetworkIsolation,
            self::$credential,
            $this->workDirectory,
            $this->outputDirectory,
            $limits ?? new ResourceLimits,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-29T12:00:01Z'),
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
            'retrieved_at' => '2026-10-29T12:00:00Z',
            'sha256' => (string) hash_file('sha256', $path),
            'byte_size' => (int) filesize($path),
            'license' => ['expression' => 'MIT', 'evidence_url' => 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/LICENSE'],
        ];
    }

    private static function inputWith(string $name, string $content): string
    {
        $path = self::$directory.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @param  callable(SigningRequest): list<string>  $command
     */
    private static function command(callable $command, bool $supported = true): SigningImplementation
    {
        return new CommandImplementation($command(...), $supported);
    }

    private static function assertSchemaValid(AttemptResult $result): void
    {
        self::assertSame([], (new SchemaValidator)->errors($result->record, AttemptRunner::ATTEMPT_SCHEMA));
    }

    private function assertWorkDirectoryIsEmpty(): void
    {
        self::assertSame([], array_values(array_diff((array) scandir($this->workDirectory), ['.', '..'])));
    }

    public function test_pdf_signer_signs_the_input_and_the_output_passes_every_validator(): void
    {
        $implementation = new PdfSignerImplementation;

        $result = $this->runner()->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSchemaValid($result);
        $record = $result->record;
        self::assertSame('succeeded', $record['execution']['outcome'], (string) ($record['execution']['error']['message'] ?? ''));
        self::assertSame('supported', $record['capability']);
        self::assertSame(self::RUN_ID.'/basic-invisible-sha256/pdf-signer/synthetic:unsigned.pdf', $record['id']);
        self::assertSame(['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => 'f27e4064be107b8a662f370a9acdcecd786da9f7']], $record['implementation']);
        self::assertSame(['status' => 'available', 'source_id' => 'synthetic:unsigned.pdf', 'sha256' => hash_file('sha256', self::$unsignedPath), 'byte_size' => filesize(self::$unsignedPath)], $record['input']);
        self::assertNotNull($result->outputPath);
        self::assertSame(['sha256' => hash_file('sha256', $result->outputPath), 'byte_size' => filesize($result->outputPath)], $record['output']);
        self::assertSame($this->outputDirectory.'/'.$record['output']['sha256'].'.pdf', $result->outputPath);
        self::assertStringNotContainsString(self::$credential->password, json_encode($record, JSON_THROW_ON_ERROR));
        $this->assertWorkDirectoryIsEmpty();

        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-29T12:00:02Z');
        $panel = new ValidationPanel([
            new PyHankoValidator(new ProcessRunner, $clock),
            new PdfsigValidator(new ProcessRunner, $clock),
            new OpenSslCmsValidator(new ProcessRunner, $clock),
            new QpdfCheckValidator(new ProcessRunner, $clock),
        ]);
        $inputArtifact = ['sha256' => $record['input']['sha256'], 'byte_size' => $record['input']['byte_size']];
        foreach ($panel->observe(new ValidationSubject($record['id'], $inputArtifact, $result->outputPath)) as $observation) {
            self::assertSame('valid', $observation['verdict'], $observation['analyzer']['id'].': '.$observation['evidence']['raw_output']);
        }
    }

    public function test_malformed_input_is_a_failed_attempt_with_the_implementation_error(): void
    {
        $malformedPath = self::inputWith('malformed.pdf', "%PDF-1.7\nthis is not a PDF body\n");

        $result = $this->runner()->run(self::RUN_ID, self::scenario(), new PdfSignerImplementation, self::availableAcquisition($malformedPath), $malformedPath);

        self::assertSchemaValid($result);
        self::assertSame('supported', $result->record['capability']);
        self::assertSame('failed', $result->record['execution']['outcome']);
        self::assertSame(1, $result->record['execution']['exit_code']);
        self::assertStringContainsString('Signing failed', $result->record['execution']['error']['message']);
        self::assertNull($result->record['output']);
        self::assertNull($result->outputPath);
        $this->assertWorkDirectoryIsEmpty();
    }

    public function test_attempt_exceeding_the_time_limit_is_timed_out_and_its_children_are_terminated(): void
    {
        $childPidPath = self::$directory.'/child.pid';
        $implementation = self::command(static fn (SigningRequest $request): array => ['sh', '-c', 'sleep 30 & echo $! > "$1"; wait', 'sh', $childPidPath]);

        $start = hrtime(true);
        $result = $this->runner(new ResourceLimits(timeoutSeconds: 0.5))->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
        self::assertSchemaValid($result);
        self::assertSame('timed_out', $result->record['execution']['outcome']);
        self::assertNull($result->record['execution']['exit_code']);
        self::assertSame('command-signer did not finish within 0.5 seconds', $result->record['execution']['error']['message']);
        self::assertNull($result->record['output']);
        self::assertTrue(ProcessStatus::endsWithin((int) file_get_contents($childPidPath), 2.0), 'The child process is still running');
        $this->assertWorkDirectoryIsEmpty();
    }

    public function test_attempt_exceeding_the_memory_limit_fails(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): array => ['php', '-d', 'memory_limit=-1', '-r', 'str_repeat("x", 512 * 1024 * 1024); file_put_contents($argv[1], "%PDF-1.7");', $request->outputPath]);

        $result = $this->runner(new ResourceLimits(memoryBytes: 256 * 1024 * 1024))->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSchemaValid($result);
        self::assertSame('failed', $result->record['execution']['outcome']);
        self::assertNull($result->record['output']);
    }

    public function test_attempt_writing_more_than_the_file_size_limit_fails(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): array => ['sh', '-c', 'head -c 2097152 /dev/zero > "$1"', 'sh', $request->outputPath]);

        $result = $this->runner(new ResourceLimits(fileSizeBytes: 1024 * 1024))->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSchemaValid($result);
        self::assertSame('failed', $result->record['execution']['outcome']);
        self::assertNull($result->record['output']);
        $this->assertWorkDirectoryIsEmpty();
    }

    public function test_attempt_sees_only_its_own_environment_and_work_directory(): void
    {
        putenv('QUALIFICATION_SECRET=do-not-leak');
        try {
            $implementation = self::command(static fn (SigningRequest $request): array => ['sh', '-c', 'env | sort > "$1"', 'sh', $request->outputPath]);
            $result = $this->runner()->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);
        } finally {
            putenv('QUALIFICATION_SECRET');
        }

        self::assertSame('succeeded', $result->record['execution']['outcome']);
        self::assertNotNull($result->outputPath);
        $environment = (string) file_get_contents($result->outputPath);
        self::assertStringNotContainsString('QUALIFICATION_SECRET', $environment);
        self::assertMatchesRegularExpression('#^HOME='.preg_quote($this->workDirectory, '#').'/attempt-[0-9a-f]+$#m', $environment);
        self::assertMatchesRegularExpression('#^TMPDIR='.preg_quote($this->workDirectory, '#').'/attempt-[0-9a-f]+$#m', $environment);
        self::assertMatchesRegularExpression('#^PWD='.preg_quote($this->workDirectory, '#').'/attempt-[0-9a-f]+$#m', $environment);
    }

    public function test_successful_exit_without_output_is_a_failed_attempt(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): array => ['true']);

        $result = $this->runner()->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSchemaValid($result);
        self::assertSame('failed', $result->record['execution']['outcome']);
        self::assertSame(0, $result->record['execution']['exit_code']);
        self::assertSame('command-signer exited with code 0 without writing an output', $result->record['execution']['error']['message']);
    }

    public function test_unavailable_input_is_not_run(): void
    {
        $acquisition = self::availableAcquisition(self::$unsignedPath);
        unset($acquisition['retrieved_at'], $acquisition['sha256'], $acquisition['byte_size'], $acquisition['license']);
        $acquisition = [
            ...$acquisition,
            'status' => 'unavailable',
            'attempted_at' => '2026-10-29T12:00:00Z',
            'reason' => 'missing_upstream',
            'expected_sha256' => (string) hash_file('sha256', self::$unsignedPath),
            'expected_byte_size' => (int) filesize(self::$unsignedPath),
            'message' => 'Upstream returned HTTP 404',
        ];

        $result = $this->runner()->run(self::RUN_ID, self::scenario(), new PdfSignerImplementation, $acquisition, null);

        self::assertSchemaValid($result);
        self::assertSame('not_evaluated', $result->record['capability']);
        self::assertSame('not_run', $result->record['execution']['outcome']);
        self::assertSame(['status' => 'unavailable', 'source_id' => 'synthetic:unsigned.pdf', 'reason' => 'missing_upstream', 'expected_sha256' => $acquisition['expected_sha256'], 'message' => 'Upstream returned HTTP 404'], $result->record['input']);
    }

    public function test_unsupported_scenario_is_not_run(): void
    {
        $scenario = self::scenario();
        $scenario['signing']['digest_algorithm'] = 'sha512';

        $result = $this->runner()->run(self::RUN_ID, $scenario, new PdfSignerImplementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSchemaValid($result);
        self::assertSame('unsupported', $result->record['capability']);
        self::assertSame('not_run', $result->record['execution']['outcome']);
        self::assertNull($result->record['output']);
    }

    public function test_input_that_differs_from_its_acquisition_record_is_refused(): void
    {
        $acquisition = self::availableAcquisition(self::$unsignedPath);
        $changedPath = self::inputWith('changed.pdf', file_get_contents(self::$unsignedPath).'%');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match its acquisition record');

        $this->runner()->run(self::RUN_ID, self::scenario(), new PdfSignerImplementation, $acquisition, $changedPath);
    }

    public function test_attempts_are_refused_when_a_network_interface_is_present(): void
    {
        $interfacesDirectory = self::$directory.'/interfaces-'.bin2hex(random_bytes(4));
        mkdir($interfacesDirectory);
        mkdir($interfacesDirectory.'/lo');
        mkdir($interfacesDirectory.'/eth0');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('eth0');

        $this->runner(networkIsolation: new NetworkIsolation($interfacesDirectory))->run(self::RUN_ID, self::scenario(), new PdfSignerImplementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);
    }

    public function test_test_credential_holds_a_sha256_rsa_certificate_and_is_removed(): void
    {
        $directory = self::$directory.'/credential-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $credential = TestCredential::generate($directory);

        self::assertTrue(openssl_pkcs12_read((string) file_get_contents($credential->pkcs12Path), $certificates, $credential->password));
        $certificate = openssl_x509_parse($certificates['cert']);
        self::assertIsArray($certificate);
        self::assertSame('RSA-SHA256', $certificate['signatureTypeSN']);

        $credential->remove();
        self::assertFileDoesNotExist($credential->pkcs12Path);
    }

    public function test_output_written_as_a_symbolic_link_is_a_failed_attempt(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): array => ['ln', '-s', self::$unsignedPath, $request->outputPath]);

        $result = $this->runner()->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSchemaValid($result);
        self::assertSame('failed', $result->record['execution']['outcome']);
        self::assertSame('command-signer did not write its output as a regular file', $result->record['execution']['error']['message']);
        self::assertNull($result->record['output']);
        self::assertDirectoryDoesNotExist($this->outputDirectory);
    }

    public function test_attempt_directory_is_removed_even_when_the_attempt_locks_a_subdirectory(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): array => ['sh', '-c', 'mkdir locked && touch locked/file && chmod 000 locked && cp input.pdf output.pdf']);

        $result = $this->runner()->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSame('succeeded', $result->record['execution']['outcome']);
        $this->assertWorkDirectoryIsEmpty();
    }

    private static function runnerThatCannotRemove(): CommandRunner
    {
        return new class implements CommandRunner
        {
            public function run(array $command, float $timeoutSeconds): CommandResult
            {
                return $command[0] === 'rm' ? new CommandResult(1, 'rm: refused') : (new ProcessRunner)->run($command, $timeoutSeconds);
            }
        };
    }

    public function test_output_is_not_kept_when_the_attempt_directory_cannot_be_removed(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): array => ['cp', 'input.pdf', 'output.pdf']);

        try {
            $this->runner(commandRunner: self::runnerThatCannotRemove())->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);
            self::fail('The attempt was recorded although its directory was not removed');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unable to remove the attempt directory', $exception->getMessage());
        }

        self::assertSame([], glob($this->outputDirectory.'/*') ?: []);
    }

    public function test_harness_error_is_kept_when_the_attempt_directory_cannot_be_removed(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): never => throw new RuntimeException('Adapter could not build its command'));

        try {
            $this->runner(commandRunner: self::runnerThatCannotRemove())->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);
            self::fail('The adapter error was not raised');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unable to remove the attempt directory', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            self::assertSame('Adapter could not build its command', $exception->getPrevious()->getMessage());
        }
    }

    public function test_work_directory_on_a_filesystem_larger_than_the_storage_limit_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than the storage limit');

        $this->runner(new ResourceLimits(storageBytes: 1024 * 1024))->run(self::RUN_ID, self::scenario(), self::command(static fn (SigningRequest $request): array => ['true']), self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);
    }

    public function test_credential_password_printed_by_an_implementation_is_redacted(): void
    {
        $implementation = self::command(static fn (SigningRequest $request): array => ['sh', '-c', 'echo "arguments: $*"; exit 1', 'sh', '--password='.$request->credential->password]);

        $result = $this->runner()->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertSame('failed', $result->record['execution']['outcome']);
        self::assertStringNotContainsString(self::$credential->password, json_encode($result->record, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('--password=[redacted]', $result->record['execution']['error']['message']);
    }

    public function test_child_left_holding_the_output_stream_does_not_turn_a_success_into_a_timeout(): void
    {
        $childPidPath = self::$directory.'/lingering-child.pid';
        $implementation = self::command(static fn (SigningRequest $request): array => ['sh', '-c', 'cp input.pdf output.pdf; sleep 30 & echo $! > "$1"; exit 0', 'sh', $childPidPath]);

        $start = hrtime(true);
        $result = $this->runner(new ResourceLimits(timeoutSeconds: 10.0))->run(self::RUN_ID, self::scenario(), $implementation, self::availableAcquisition(self::$unsignedPath), self::$unsignedPath);

        self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
        self::assertSame('succeeded', $result->record['execution']['outcome']);
        self::assertTrue(ProcessStatus::endsWithin((int) file_get_contents($childPidPath), 2.0), 'The child process is still running');
    }
}
