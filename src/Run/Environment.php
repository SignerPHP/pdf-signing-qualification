<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use RuntimeException;

final class Environment
{
    public const string IMAGE_VARIABLE = 'QUALIFICATION_IMAGE_ID';

    public static function describe(string|false $imageId, string $osReleasePath = '/etc/os-release'): array
    {
        return [
            'os' => [
                'name' => self::operatingSystemName($osReleasePath),
                'kernel' => php_uname('r'),
                'architecture' => php_uname('m'),
            ],
            'container_image' => self::containerImage($imageId),
            'php_version' => PHP_VERSION,
        ];
    }

    private static function operatingSystemName(string $osReleasePath): string
    {
        $release = is_file($osReleasePath) ? parse_ini_file($osReleasePath, false, INI_SCANNER_RAW) : false;
        $name = is_array($release) ? ($release['PRETTY_NAME'] ?? null) : null;
        if (! is_string($name) || trim($name, " \"'") === '') {
            throw new RuntimeException("Unable to read the operating system name from {$osReleasePath}");
        }

        return trim($name, " \"'");
    }

    private static function containerImage(string|false $imageId): array
    {
        if ($imageId === false || $imageId === '') {
            return ['status' => 'unavailable', 'reason' => self::IMAGE_VARIABLE.' was not set, so the image this run used was not identified'];
        }
        if (preg_match('/^sha256:[0-9a-f]{64}$/', $imageId) !== 1) {
            throw new RuntimeException(self::IMAGE_VARIABLE." must be an image id such as sha256:<64 hexadecimal digits>, got {$imageId}");
        }

        return ['status' => 'available', 'id' => $imageId];
    }
}
