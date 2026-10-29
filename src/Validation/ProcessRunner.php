<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class ProcessRunner implements CommandRunner
{
    public function run(array $command, float $timeoutSeconds): CommandResult
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if ($process === false) {
            throw new RuntimeException('Unable to start '.$command[0]);
        }

        $stdout = $pipes[1];
        stream_set_blocking($stdout, false);
        $deadline = hrtime(true) + (int) ($timeoutSeconds * 1e9);
        $output = '';
        $timedOut = false;

        while (! feof($stdout)) {
            $remainingMicroseconds = intdiv($deadline - hrtime(true), 1000);
            if ($remainingMicroseconds <= 0) {
                $timedOut = true;
                break;
            }

            $read = [$stdout];
            $write = null;
            $except = null;
            if (stream_select($read, $write, $except, intdiv($remainingMicroseconds, 1_000_000), $remainingMicroseconds % 1_000_000) > 0) {
                $output .= (string) fread($stdout, 65_536);
            }
        }

        fclose($stdout);

        $status = proc_get_status($process);
        while ($status['running']) {
            if (! $timedOut && hrtime(true) >= $deadline) {
                $timedOut = true;
            }
            if ($timedOut) {
                proc_terminate($process, 9);
            }
            usleep(1_000);
            $status = proc_get_status($process);
        }
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
