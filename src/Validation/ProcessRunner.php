<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\FilesystemConfinement;

final class ProcessRunner implements CommandRunner
{
    private const int POLL_MICROSECONDS = 50_000;

    private const int DRAIN_NANOSECONDS = 1_000_000_000;

    private const float MEASUREMENT_GRACE_SECONDS = 10.0;

    private const string PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    public function __construct(
        private readonly string $measurementProcess = AttemptRunner::MEASUREMENT_PROCESS,
    ) {}

    public function run(array $command, float $timeoutSeconds, ?CommandAccess $access = null): CommandResult
    {
        if ($access !== null) {
            return $this->runConfined($command, $timeoutSeconds, $access);
        }

        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if ($process === false) {
            throw new RuntimeException('Unable to start '.$command[0]);
        }

        $processId = proc_get_status($process)['pid'];
        $stdout = $pipes[1];
        stream_set_blocking($stdout, false);
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $output = '';
        $timedOut = false;
        $exitStatus = null;
        $readDeadline = $deadline;

        while (! feof($stdout)) {
            if ($exitStatus === null) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    $exitStatus = $status;
                    @posix_kill(-$processId, 9);
                    $readDeadline = min($deadline, hrtime(true) + self::DRAIN_NANOSECONDS);
                }
            }

            $remainingMicroseconds = intdiv($readDeadline - hrtime(true), 1000);
            if ($remainingMicroseconds <= 0) {
                $timedOut = $exitStatus === null;
                break;
            }

            $waitMicroseconds = min($remainingMicroseconds, self::POLL_MICROSECONDS);
            $read = [$stdout];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, 0, $waitMicroseconds) > 0) {
                $output .= (string) fread($stdout, 65_536);
            }
        }

        fclose($stdout);

        $status = $exitStatus ?? proc_get_status($process);
        while ($status['running']) {
            if (! $timedOut && hrtime(true) >= $deadline) {
                $timedOut = true;
            }
            if ($timedOut) {
                @posix_kill(-$processId, 9);
                proc_terminate($process, 9);
            }
            usleep(1_000);
            $status = proc_get_status($process);
        }
        @posix_kill(-$processId, 9);
        proc_close($process);

        if ($timedOut) {
            return new CommandResult(null, $output, true);
        }
        if ($status['signaled']) {
            return new CommandResult(null, $output, terminationSignal: $status['termsig']);
        }

        return new CommandResult($status['exitcode'] < 0 ? null : $status['exitcode'], $output);
    }

    /**
     * @param  list<string>  $command
     */
    private function runConfined(array $command, float $timeoutSeconds, CommandAccess $access): CommandResult
    {
        $workDirectory = self::createWorkDirectory();
        $measurementPath = $workDirectory.'.measurement.json';
        $measurementHandle = null;
        try {
            $measurementHandle = @fopen($measurementPath, 'x+');
            if ($measurementHandle === false || ! chmod($measurementPath, 0600)) {
                throw new RuntimeException("Unable to create the measurement file {$measurementPath}");
            }
            $result = $this->run([
                'setsid',
                'env', '-i', 'PATH='.self::PATH, "HOME={$workDirectory}", "TMPDIR={$workDirectory}",
                'python3', '-I', $this->measurementProcess, (string) $timeoutSeconds, $measurementPath,
                ...(new FilesystemConfinement($this))->arguments($workDirectory, $access->readablePaths, $access->writablePaths),
                '--', ...$command,
            ], $timeoutSeconds + self::MEASUREMENT_GRACE_SECONDS);
            $measured = json_decode((string) stream_get_contents($measurementHandle, offset: 0), true);
            if (! is_array($measured)) {
                if ($result->timedOut) {
                    return $result;
                }
                throw new RuntimeException("The measuring process of {$command[0]} {$result->describe()} and wrote no measurement, so it is unknown whether the command ran confined");
            }
            if (is_string($measured['confinement_error'] ?? null)) {
                throw new RuntimeException("Unable to confine {$command[0]}: {$measured['confinement_error']}");
            }
            if (($measured['timed_out'] ?? false) === true) {
                return new CommandResult(null, $result->output, true);
            }

            return $result;
        } finally {
            if (is_resource($measurementHandle)) {
                fclose($measurementHandle);
            }
            $this->run(['chmod', '-R', 'u+rwx', '--', $workDirectory], 60.0);
            $removal = $this->run(['rm', '-rf', '--', $workDirectory, $measurementPath], 60.0);
            if ($removal->exitCode !== 0 || file_exists($workDirectory) || file_exists($measurementPath)) {
                throw new RuntimeException("Unable to remove the work directory {$workDirectory} of {$command[0]}: {$removal->output}");
            }
        }
    }

    private static function createWorkDirectory(): string
    {
        $directory = sys_get_temp_dir().'/validator-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException("Unable to create the work directory {$directory}");
        }

        return $directory;
    }
}
