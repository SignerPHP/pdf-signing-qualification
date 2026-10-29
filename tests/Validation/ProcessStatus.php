<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

final class ProcessStatus
{
    public static function endsWithin(int $pid, float $seconds): bool
    {
        $deadline = hrtime(true) + (int) ($seconds * 1e9);
        do {
            $status = @file_get_contents("/proc/{$pid}/stat");
            if ($status === false || preg_match('/^\d+ \(.*\) Z /', $status) === 1) {
                return true;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);

        return false;
    }
}
