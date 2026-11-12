<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

final class SupportedSigning
{
    /**
     * @param  list<array<string, mixed>>  $supported
     */
    public static function matches(array $signing, array $supported): bool
    {
        $normalized = self::normalize($signing);
        foreach ($supported as $candidate) {
            if (self::normalize($candidate) === $normalized) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(array $value): array
    {
        ksort($value);

        return array_map(static fn (mixed $entry): mixed => is_array($entry) ? self::normalize($entry) : $entry, $value);
    }
}
