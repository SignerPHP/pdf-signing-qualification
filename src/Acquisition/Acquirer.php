<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class Acquirer
{
    public const string RECORD_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/acquisition.schema.json';

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly string $cacheDirectory,
        private readonly Closure $clock,
    ) {}

    public function acquire(Input $input): array
    {
        $filePath = $this->cacheDirectory.'/'.$input->source->id.'/'.$input->path;
        $recordPath = $filePath.'.acquisition.json';

        $cachedRecord = $this->verifiedCachedRecord($input, $filePath, $recordPath);
        if ($cachedRecord !== null) {
            return $cachedRecord;
        }

        $this->remove($filePath, $recordPath);
        $now = $this->now();

        try {
            $content = $this->fetcher->fetch($input->fileUrl());
        } catch (UpstreamFileMissing $missing) {
            return $this->unavailable($input, $now, 'missing_upstream', $missing->getMessage());
        }

        $observedSha256 = hash('sha256', $content);
        $observedByteSize = strlen($content);
        if ($observedSha256 !== $input->sha256 || $observedByteSize !== $input->byteSize) {
            return $this->unavailable($input, $now, 'hash_mismatch', "Upstream file has SHA-256 {$observedSha256} and {$observedByteSize} bytes, expected {$input->sha256} and {$input->byteSize} bytes", [
                'observed_sha256' => $observedSha256,
                'observed_byte_size' => $observedByteSize,
            ]);
        }

        $record = $this->record($input, 'available') + [
            'retrieved_at' => $now,
            'sha256' => $observedSha256,
            'byte_size' => $observedByteSize,
            'license' => $input->license,
        ];

        $this->write($filePath, $content);
        $this->write($recordPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return $record;
    }

    private function verifiedCachedRecord(Input $input, string $filePath, string $recordPath): ?array
    {
        if (! is_file($filePath) || ! is_file($recordPath)) {
            return null;
        }

        $record = json_decode((string) file_get_contents($recordPath), true);
        $expectedRecord = $this->record($input, 'available') + ['sha256' => $input->sha256, 'byte_size' => $input->byteSize, 'license' => $input->license];
        if (! is_array($record) || array_intersect_key($record, $expectedRecord) != $expectedRecord) {
            return null;
        }

        if (filesize($filePath) !== $input->byteSize || hash_file('sha256', $filePath) !== $input->sha256) {
            return null;
        }

        return $record;
    }

    private function record(Input $input, string $status): array
    {
        return [
            'schema' => self::RECORD_SCHEMA,
            'source_id' => $input->id(),
            'source' => [
                'id' => $input->source->id,
                'url' => $input->source->url,
                'revision' => $input->source->revision,
                'license' => $input->source->license,
            ],
            'path' => $input->path,
            'file_url' => $input->fileUrl(),
            'status' => $status,
        ];
    }

    /**
     * @param  array{observed_sha256?: string, observed_byte_size?: int}  $observed
     */
    private function unavailable(Input $input, string $attemptedAt, string $reason, string $message, array $observed = []): array
    {
        return $this->record($input, 'unavailable') + [
            'attempted_at' => $attemptedAt,
            'reason' => $reason,
            'expected_sha256' => $input->sha256,
            'expected_byte_size' => $input->byteSize,
        ] + $observed + ['message' => $message];
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function remove(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException("Unable to remove {$path} from the input cache");
            }
        }
    }

    private function write(string $path, string $content): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the cache directory {$directory}");
        }

        $partialPath = $path.'.part';
        if (file_put_contents($partialPath, $content) !== strlen($content) || ! rename($partialPath, $path)) {
            throw new RuntimeException("Unable to write {$path} to the input cache");
        }
    }
}
