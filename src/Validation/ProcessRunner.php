<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class ProcessRunner implements CommandRunner
{
    private const int POLL_MICROSECONDS = 50_000;

    private const int DRAIN_NANOSECONDS = 1_000_000_000;

    public function run(array $command, float $timeoutSeconds): CommandResult
    {
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
}
