<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

use RuntimeException;

final class SyntheticInput
{
    public const string REPOSITORY_URL = 'https://github.com/SignerPHP/pdf-signing-qualification';

    private const string GENERATOR_NAMESPACE = 'SignerPHP\\PdfSigningQualification\\Tests\\Fixtures\\Synthetic\\';

    public static function generate(string $generator, string $directory, string $purpose): string
    {
        $class = self::GENERATOR_NAMESPACE.basename($generator, '.php');
        if (! class_exists($class) || ! method_exists($class, 'create')) {
            throw new RuntimeException("The generator {$generator} of {$purpose} does not define {$class}::create()");
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException("Unable to create {$directory}");
        }
        $path = $class::create($directory);
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException("The generator {$generator} of {$purpose} did not write a file");
        }

        return $path;
    }

    public static function record(string $sourceId, string $source, string $path, string $generator): array
    {
        return [
            'schema' => Acquirer::RECORD_SCHEMA,
            'source_id' => $sourceId,
            'source' => ['id' => $source, 'url' => self::REPOSITORY_URL, 'revision' => ['kind' => 'version', 'value' => '1'], 'license' => self::license()],
            'path' => $path,
            'file_url' => self::REPOSITORY_URL.'/blob/main/'.$generator,
        ];
    }

    /**
     * @param  array{sha256: string, byte_size: int}  $artifact
     */
    public static function available(array $record, array $artifact, string $retrievedAt): array
    {
        return $record + ['status' => 'available', 'retrieved_at' => $retrievedAt, 'sha256' => $artifact['sha256'], 'byte_size' => $artifact['byte_size'], 'license' => self::license()];
    }

    private static function license(): array
    {
        return ['expression' => 'MIT', 'evidence_url' => self::REPOSITORY_URL.'/blob/main/LICENSE'];
    }
}
