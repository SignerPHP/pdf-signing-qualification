<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use Throwable;

final class AttemptRunner
{
    public const string ATTEMPT_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/attempt.schema.json';

    public const string MEASUREMENT_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v2/measurement.schema.json';

    public const string MEASUREMENT_PROCESS = '/opt/measurement/measure-process-tree.py';

    private const float MEASUREMENT_GRACE_SECONDS = 10.0;

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

    public function run(string $runId, array $scenario, SigningImplementation $implementation, array $acquisition, ?string $inputPath, bool $rejectionExpected = false): AttemptResult
    {
        Scenarios::assertValid($scenario);
        (new SchemaValidator)->assertValid($acquisition, Acquirer::RECORD_SCHEMA, 'acquisition record');

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

        $this->assertContained();
        $attemptDirectory = $this->createAttemptDirectory();
        $measurementPath = self::measurementPath($attemptDirectory);
        $measurementHandle = null;
        $stagedOutputPath = null;
        try {
            $measurementHandle = self::createMeasurement($measurementPath);
            $request = new SigningRequest($this->copyInput($inputPath, $attemptDirectory, $acquisition), $attemptDirectory.'/output.pdf', $this->credential, $scenario['signing']);

            $startedAt = $this->now();
            $result = $this->runner->run($this->sandboxed($this->measured($implementation->command($request), $measurementPath), $attemptDirectory), $this->limits->timeoutSeconds + self::MEASUREMENT_GRACE_SECONDS);
            $finishedAt = $this->now();
            $measured = self::readMeasurement($measurementHandle);
            $timedOut = $result->timedOut || ($measured['timed_out'] ?? false) === true;

            [$outcome, $error] = match (true) {
                $timedOut => ['timed_out', "{$implementation->id()} did not finish within {$this->limits->timeoutSeconds} seconds"],
                $result->exitCode !== 0 => ['failed', $this->failure($implementation, $result)],
                ! file_exists($request->outputPath) && ! is_link($request->outputPath) => ['failed', "{$implementation->id()} exited with code 0 without writing an output"],
                is_link($request->outputPath) || ! is_file($request->outputPath) => ['failed', "{$implementation->id()} did not write its output as a regular file"],
                default => ['succeeded', null],
            };
            if ($outcome === 'succeeded') {
                $stagedOutputPath = $this->stageOutput($request->outputPath);
            }
        } catch (Throwable $exception) {
            $this->removeDirectory($attemptDirectory, $exception);

            throw $exception;
        } finally {
            if ($measurementHandle !== null) {
                fclose($measurementHandle);
            }
        }

        try {
            $this->removeDirectory($attemptDirectory);
        } catch (RuntimeException $exception) {
            if ($stagedOutputPath !== null) {
                @unlink($stagedOutputPath);
            }

            throw $exception;
        }
        $outputPath = $stagedOutputPath === null ? null : $this->keepOutput($stagedOutputPath);
        $measurement = [
            'schema' => self::MEASUREMENT_SCHEMA,
            'attempt_id' => $record['id'],
            'boundary' => 'process_tree',
            'instrumented' => false,
            'execution_outcome' => $outcome,
            'metrics' => self::metrics($measured, $timedOut ? 'did not finish within the time limit' : $result->describe()),
        ];
        (new SchemaValidator)->assertValid($measurement, self::MEASUREMENT_SCHEMA, 'measurement');

        return self::result($record + [
            'capability' => $rejectionExpected ? 'expected_rejection' : 'supported',
            'execution' => [
                'outcome' => $outcome,
                'exit_code' => $timedOut ? null : $result->exitCode,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
                'error' => $error === null ? null : ['message' => $error],
            ],
            'output' => $outputPath === null ? null : Artifact::describe($outputPath),
        ], $outputPath, $measurement);
    }

    public function incompatibility(SigningImplementation $implementation): ?string
    {
        $check = $implementation->compatibilityCheck();
        if ($check === null) {
            return null;
        }

        $this->assertContained();
        $attemptDirectory = $this->createAttemptDirectory();
        try {
            $result = $this->runner->run($this->sandboxed($check['command'], $attemptDirectory), $this->limits->timeoutSeconds);
        } finally {
            $this->removeDirectory($attemptDirectory);
        }

        if ($result->timedOut || $result->exitCode !== 0) {
            $output = trim(mb_scrub(substr($result->output, -self::ERROR_OUTPUT_BYTES), 'UTF-8'));

            return "The help of {$implementation->id()} {$result->describe()}".($output === '' ? '' : ": {$output}");
        }
        $missing = array_values(array_filter($check['options'], static fn (string $option): bool => preg_match('/(?<![A-Za-z0-9-])'.preg_quote($option, '/').'(?![A-Za-z0-9-])/', $result->output) !== 1));

        return $missing === [] ? null : "{$implementation->id()} does not list ".implode(', ', $missing).' in its help, so the adapter cannot run this revision';
    }

    public function assertContained(): void
    {
        $this->networkIsolation->assertIsolated();
        $this->assertBoundedStorage();
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

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function measured(array $command, string $measurementPath): array
    {
        return ['python3', '-I', self::MEASUREMENT_PROCESS, (string) $this->limits->timeoutSeconds, $measurementPath, '--', ...$command];
    }

    private static function measurementPath(string $attemptDirectory): string
    {
        return $attemptDirectory.'.measurement.json';
    }

    /**
     * @return resource
     */
    private static function createMeasurement(string $measurementPath)
    {
        $handle = @fopen($measurementPath, 'x+');
        if ($handle === false || ! chmod($measurementPath, 0600)) {
            throw new RuntimeException("Unable to create the measurement file {$measurementPath}");
        }

        return $handle;
    }

    /**
     * @param  resource  $measurementHandle
     * @return array{timed_out: bool, wall_time_seconds: float, cpu_user_seconds: float, cpu_system_seconds: float, peak_resident_kibibytes: int}|null
     */
    private static function readMeasurement($measurementHandle): ?array
    {
        $contents = rewind($measurementHandle) ? stream_get_contents($measurementHandle) : false;
        if ($contents === false || $contents === '') {
            return null;
        }
        $measured = json_decode($contents, true);
        if (! is_array($measured) || ! is_bool($measured['timed_out'] ?? null) || ! is_int($measured['peak_resident_kibibytes'] ?? null) || $measured['peak_resident_kibibytes'] < 0) {
            return null;
        }
        foreach (['wall_time_seconds', 'cpu_user_seconds', 'cpu_system_seconds'] as $name) {
            if (! is_int($measured[$name] ?? null) && ! is_float($measured[$name] ?? null) || $measured[$name] < 0) {
                return null;
            }
        }

        return [
            'timed_out' => $measured['timed_out'],
            'wall_time_seconds' => (float) $measured['wall_time_seconds'],
            'cpu_user_seconds' => (float) $measured['cpu_user_seconds'],
            'cpu_system_seconds' => (float) $measured['cpu_system_seconds'],
            'peak_resident_kibibytes' => $measured['peak_resident_kibibytes'],
        ];
    }

    /**
     * @param  array{timed_out: bool, wall_time_seconds: float, cpu_user_seconds: float, cpu_system_seconds: float, peak_resident_kibibytes: int}|null  $measured
     */
    private static function metrics(?array $measured, string $ending): array
    {
        if ($measured === null) {
            $unavailable = ['status' => 'unavailable', 'reason' => "The measurement process wrote no measurement; the attempt {$ending}"];

            return ['wall_time_seconds' => $unavailable, 'cpu_user_seconds' => $unavailable, 'cpu_system_seconds' => $unavailable, 'peak_memory_bytes' => $unavailable];
        }

        return [
            'wall_time_seconds' => ['status' => 'available', 'value' => $measured['wall_time_seconds']],
            'cpu_user_seconds' => ['status' => 'available', 'value' => $measured['cpu_user_seconds']],
            'cpu_system_seconds' => ['status' => 'available', 'value' => $measured['cpu_system_seconds']],
            'peak_memory_bytes' => ['status' => 'available', 'value' => $measured['peak_resident_kibibytes'] * 1024],
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

    private function failure(SigningImplementation $implementation, CommandResult $result): string
    {
        $output = trim(mb_scrub(substr(str_replace($this->credential->password, '[redacted]', $result->output), -self::ERROR_OUTPUT_BYTES), 'UTF-8'));

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
        if (Artifact::describe($copyPath) !== ['sha256' => $acquisition['sha256'], 'byte_size' => $acquisition['byte_size']]) {
            throw new RuntimeException("The input {$inputPath} does not match its acquisition record for {$acquisition['source_id']}");
        }

        return $copyPath;
    }

    private function assertBoundedStorage(): void
    {
        $totalBytes = disk_total_space($this->workDirectory);
        if ($totalBytes === false) {
            throw new RuntimeException("Unable to read the size of the filesystem of {$this->workDirectory}");
        }
        if ($totalBytes > $this->limits->storageBytes) {
            throw new RuntimeException("The work directory {$this->workDirectory} is on a filesystem of {$totalBytes} bytes, larger than the storage limit of {$this->limits->storageBytes} bytes. Use the sandbox tmpfs.");
        }
    }

    private function stageOutput(string $outputPath): string
    {
        if (! is_dir($this->outputDirectory) && ! mkdir($this->outputDirectory, 0777, true) && ! is_dir($this->outputDirectory)) {
            throw new RuntimeException("Unable to create the output directory {$this->outputDirectory}");
        }

        $stagedPath = $this->outputDirectory.'/.staged-'.bin2hex(random_bytes(8));
        if (! copy($outputPath, $stagedPath)) {
            throw new RuntimeException("Unable to copy the output {$outputPath}");
        }

        return $stagedPath;
    }

    private function keepOutput(string $stagedPath): string
    {
        $keptPath = $this->outputDirectory.'/'.Artifact::describe($stagedPath)['sha256'].'.pdf';
        if (! rename($stagedPath, $keptPath)) {
            throw new RuntimeException("Unable to keep the output {$stagedPath}");
        }

        return $keptPath;
    }

    private function removeDirectory(string $directory, ?Throwable $previous = null): void
    {
        $measurementPath = self::measurementPath($directory);
        $this->runner->run(['chmod', '-R', 'u+rwx', '--', $directory], 60.0);
        if (is_dir($measurementPath) && ! is_link($measurementPath)) {
            $this->runner->run(['chmod', '-R', 'u+rwx', '--', $measurementPath], 60.0);
        }
        $result = $this->runner->run(['rm', '-rf', '--', $directory, $measurementPath], 60.0);
        if ($result->exitCode !== 0 || file_exists($directory) || file_exists($measurementPath) || is_link($measurementPath)) {
            throw new RuntimeException("Unable to remove the attempt directory {$directory}: {$result->output}", previous: $previous);
        }
    }

    private static function result(array $record, ?string $outputPath, ?array $measurement = null): AttemptResult
    {
        (new SchemaValidator)->assertValid($record, self::ATTEMPT_SCHEMA, 'attempt record');

        return new AttemptResult($record, $outputPath, $measurement);
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
