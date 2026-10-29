<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification;

use RuntimeException;

final class Artifact
{
    /**
     * @return array{sha256: string, byte_size: int}
     */
    public static function describe(string $path): array
    {
        $sha256 = hash_file('sha256', $path);
        $byteSize = filesize($path);
        if ($sha256 === false || $byteSize === false) {
            throw new RuntimeException("Unable to read {$path}");
        }

        return ['sha256' => $sha256, 'byte_size' => $byteSize];
    }
}
