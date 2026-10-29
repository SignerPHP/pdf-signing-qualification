<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

final class AttemptRunner
{
    public const string ATTEMPT_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/attempt.schema.json';

    public const string SCENARIO_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/scenario.schema.json';

    private const string PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    private const int ERROR_OUTPUT_BYTES = 4_096;

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly NetworkIsolation $networkIsolation,
        private readonly TestCredential $credential,
        private readonly string $workDirectory,
        private readonly string $outputDirectory,
        private readonly ResourceLimits $limits,
        private readonly Closure $clock,
    ) {}

    public function run(string $runId, array $scenario, SigningImplementation $implementation, array $acquisition, ?string $inputPath): AttemptResult
    {
        self::assertValid($scenario, self::SCENARIO_SCHEMA, 'scenario');
        self::assertValid($acquisition, Acquirer::RECORD_SCHEMA, 'acquisition record');

        $record = [
            'schema' => self::ATTEMPT_SCHEMA,
            'id' => "{$runId}/{$scenario['id']}/{$implementation->id()}/{$acquisition['source_id']}",
            'run_id' => $runId,
            'scenario_id' => $scenario['id'],
            'implementation' => ['id' => $implementation->id(), 'revision' => $implementation->revision()],
            'input' => self::input($acquisition),
        ];
        $notRun = ['outcome' => 'not_run', 'exit_code' => null, 'started_at' => null, 'finished_at' => null, 'error' => null];

        if ($acquisition['status'] !== 'available') {
            return self::result($record + ['capability' => 'not_evaluated', 'execution' => $notRun, 'output' => null], null);
        }
        if (! $implementation->supports($scenario['signing'])) {
            return self::result($record + ['capability' => 'unsupported', 'execution' => $notRun, 'output' => null], null);
        }
        if ($inputPath === null) {
            throw new RuntimeException("The available input {$acquisition['source_id']} has no file");
        }

        $this->networkIsolation->assertIsolated();
        $attemptDirectory = $this->createAttemptDirectory();
        try {
            $request = new SigningRequest($this->copyInput($inputPath, $attemptDirectory, $acquisition), $attemptDirectory.'/output.pdf', $this->credential, $scenario['signing']);

            $startedAt = $this->now();
            $result = $this->runner->run($this->sandboxed($implementation->command($request), $attemptDirectory), $this->limits->timeoutSeconds);
            $finishedAt = $this->now();

            $outputPath = null;
            [$outcome, $error] = match (true) {
                $result->timedOut => ['timed_out', "{$implementation->id()} did not finish within {$this->limits->timeoutSeconds} seconds"],
                $result->exitCode !== 0 => ['failed', self::failure($implementation, $result)],
                ! is_file($request->outputPath) => ['failed', "{$implementation->id()} exited with code 0 without writing an output"],
                default => ['succeeded', null],
            };
            if ($outcome === 'succeeded') {
                $outputPath = $this->keepOutput($request->outputPath);
            }
        } finally {
            $this->removeDirectory($attemptDirectory);
        }

        return self::result($record + [
            'capability' => 'supported',
            'execution' => [
                'outcome' => $outcome,
                'exit_code' => $result->exitCode,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'error' => $error === null ? null : ['message' => $error],
            ],
            'output' => $outputPath === null ? null : self::artifact($outputPath),
        ], $outputPath);
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function sandboxed(array $command, string $attemptDirectory): array
    {
        return [
            'setsid',
            'env', '-i', '-C', $attemptDirectory, 'PATH='.self::PATH, "HOME={$attemptDirectory}", "TMPDIR={$attemptDirectory}",
            'prlimit', ...$this->limits->prlimitArguments(), '--',
            ...$command,
        ];
    }

    private static function input(array $acquisition): array
    {
        if ($acquisition['status'] === 'available') {
            return ['status' => 'available', 'source_id' => $acquisition['source_id'], 'sha256' => $acquisition['sha256'], 'byte_size' => $acquisition['byte_size']];
        }

        $input = ['status' => 'unavailable', 'source_id' => $acquisition['source_id'], 'reason' => $acquisition['reason'], 'expected_sha256' => $acquisition['expected_sha256']];
        if (isset($acquisition['observed_sha256'])) {
            $input['observed_sha256'] = $acquisition['observed_sha256'];
        }

        return $input + ['message' => $acquisition['message']];
    }

    private static function failure(SigningImplementation $implementation, CommandResult $result): string
    {
        $output = trim(mb_scrub(substr($result->output, -self::ERROR_OUTPUT_BYTES), 'UTF-8'));

        return "{$implementation->id()} {$result->describe()}".($output === '' ? '' : ": {$output}");
    }

    private function createAttemptDirectory(): string
    {
        $directory = $this->workDirectory.'/attempt-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException("Unable to create the attempt directory {$directory}");
        }

        return $directory;
    }

    private function copyInput(string $inputPath, string $attemptDirectory, array $acquisition): string
    {
        $copyPath = $attemptDirectory.'/input.pdf';
        if (! copy($inputPath, $copyPath) || ! chmod($copyPath, 0400)) {
            throw new RuntimeException("Unable to copy the input {$inputPath} into the attempt directory");
        }
        if (self::artifact($copyPath) !== ['sha256' => $acquisition['sha256'], 'byte_size' => $acquisition['byte_size']]) {
            throw new RuntimeException("The input {$inputPath} does not match its acquisition record for {$acquisition['source_id']}");
        }

        return $copyPath;
    }

    private function keepOutput(string $outputPath): string
    {
        if (! is_dir($this->outputDirectory) && ! mkdir($this->outputDirectory, 0777, true) && ! is_dir($this->outputDirectory)) {
            throw new RuntimeException("Unable to create the output directory {$this->outputDirectory}");
        }

        $keptPath = $this->outputDirectory.'/'.self::artifact($outputPath)['sha256'].'.pdf';
        if (! rename($outputPath, $keptPath)) {
            throw new RuntimeException("Unable to keep the output {$outputPath}");
        }

        return $keptPath;
    }

    /**
     * @return array{sha256: string, byte_size: int}
     */
    private static function artifact(string $path): array
    {
        $sha256 = hash_file('sha256', $path);
        $byteSize = filesize($path);
        if ($sha256 === false || $byteSize === false) {
            throw new RuntimeException("Unable to read {$path}");
        }

        return ['sha256' => $sha256, 'byte_size' => $byteSize];
    }

    private function removeDirectory(string $directory): void
    {
        $result = $this->runner->run(['rm', '-rf', '--', $directory], 60.0);
        if ($result->exitCode !== 0 || file_exists($directory)) {
            throw new RuntimeException("Unable to remove the attempt directory {$directory}: {$result->output}");
        }
    }

    private static function assertValid(array $document, string $schemaId, string $description): void
    {
        $errors = (new SchemaValidator)->errors($document, $schemaId);
        if ($errors !== []) {
            throw new RuntimeException("Invalid {$description}: ".implode('; ', $errors));
        }
    }

    private static function result(array $record, ?string $outputPath): AttemptResult
    {
        self::assertValid($record, self::ATTEMPT_SCHEMA, 'attempt record');

        return new AttemptResult($record, $outputPath);
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
