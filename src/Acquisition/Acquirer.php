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
    public const string RECORD_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v2/acquisition.schema.json';

    public const string SINGLE_ORIGIN_RECORD_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/acquisition.schema.json';

    private const array ORIGIN_IDENTITY = ['source' => true, 'path' => true, 'file_url' => true];

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
        $filePath = self::filePath($this->cacheDirectory, $input);
        $recordPath = $this->cacheDirectory.'/records/'.$input->source->id.'/'.$input->path;

        $cachedRecord = $this->verifiedCachedRecord($input, $filePath, $recordPath);
        if ($cachedRecord !== null) {
            return $cachedRecord;
        }

        $this->remove($filePath, $recordPath);
        $now = $this->now();

        $content = null;
        $origins = [];
        $transportFailure = null;
        foreach ($input->origins() as $origin) {
            try {
                [$originRecord, $originContent] = $this->acquireOrigin($input, $origin);
            } catch (RuntimeException $failure) {
                $transportFailure ??= $failure;
                $origins[] = self::originIdentity($origin) + ['status' => 'unavailable', 'reason' => 'unreachable', 'message' => $failure->getMessage()];

                continue;
            }
            $origins[] = $originRecord;
            $content ??= $originContent;
        }

        if ($content === null && $transportFailure !== null) {
            throw $transportFailure;
        }
        if ($content === null) {
            return $this->unavailable($input, $now, $origins);
        }

        $record = $this->record($input, 'available') + [
            'retrieved_at' => $now,
            'sha256' => $input->sha256,
            'byte_size' => $input->byteSize,
            'license' => $input->license,
            'origins' => $origins,
        ];

        $this->write($filePath, $content);
        $this->write($recordPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return $record;
    }

    public static function filePath(string $cacheDirectory, Input $input): string
    {
        return $cacheDirectory.'/files/'.$input->source->id.'/'.$input->path;
    }

    public static function assertReadableRecord(array $record, string $subject): void
    {
        $schema = ($record['schema'] ?? null) === self::SINGLE_ORIGIN_RECORD_SCHEMA ? self::SINGLE_ORIGIN_RECORD_SCHEMA : self::RECORD_SCHEMA;
        (new SchemaValidator)->assertValid($record, $schema, $subject);
    }

    private function verifiedCachedRecord(Input $input, string $filePath, string $recordPath): ?array
    {
        if (! is_file($filePath) || ! is_file($recordPath)) {
            return null;
        }

        $record = json_decode((string) file_get_contents($recordPath), true);
        if (! is_array($record) || (new SchemaValidator)->errors($record, self::RECORD_SCHEMA) !== []) {
            return null;
        }

        $recordedOriginIdentities = array_map(static fn (array $origin): array => array_intersect_key($origin, self::ORIGIN_IDENTITY), $record['origins']);
        if ($recordedOriginIdentities !== array_map(self::originIdentity(...), $input->origins())) {
            return null;
        }

        $expectedRecord = $this->record($input, 'available') + ['retrieved_at' => $record['retrieved_at'], 'sha256' => $input->sha256, 'byte_size' => $input->byteSize, 'license' => $input->license, 'origins' => $record['origins']];
        if ($record !== $expectedRecord) {
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
        ] + self::originIdentity(new Origin($input->source, $input->path)) + [
            'status' => $status,
        ];
    }

    /**
     * @return array{source: array, path: string, file_url: string}
     */
    private static function originIdentity(Origin $origin): array
    {
        return [
            'source' => [
                'id' => $origin->source->id,
                'url' => $origin->source->url,
                'revision' => $origin->source->revision,
                'license' => $origin->source->license,
            ],
            'path' => $origin->path,
            'file_url' => $origin->fileUrl(),
        ];
    }

    /**
     * @return array{0: array, 1: ?string}
     */
    private function acquireOrigin(Input $input, Origin $origin): array
    {
        $identity = self::originIdentity($origin);
        try {
            $content = $this->fetcher->fetch($origin->fileUrl());
        } catch (UpstreamFileMissing $missing) {
            return [$identity + ['status' => 'unavailable', 'reason' => 'missing_upstream', 'message' => $missing->getMessage()], null];
        }

        $observedSha256 = hash('sha256', $content);
        $observedByteSize = strlen($content);
        if ($observedSha256 !== $input->sha256 || $observedByteSize !== $input->byteSize) {
            return [$identity + [
                'status' => 'unavailable',
                'reason' => 'hash_mismatch',
                'observed_sha256' => $observedSha256,
                'observed_byte_size' => $observedByteSize,
                'message' => "Upstream file has SHA-256 {$observedSha256} and {$observedByteSize} bytes, expected {$input->sha256} and {$input->byteSize} bytes",
            ], null];
        }

        return [$identity + ['status' => 'available'], $content];
    }

    /**
     * @param  list<array>  $origins
     */
    private function unavailable(Input $input, string $attemptedAt, array $origins): array
    {
        $changedOrigins = array_values(array_filter($origins, static fn (array $origin): bool => $origin['reason'] === 'hash_mismatch'));
        $observed = $changedOrigins === [] ? [] : [
            'observed_sha256' => $changedOrigins[0]['observed_sha256'],
            'observed_byte_size' => $changedOrigins[0]['observed_byte_size'],
        ];
        $messages = count($origins) === 1
            ? [$origins[0]['message']]
            : array_map(static fn (array $origin): string => "{$origin['source']['id']}:{$origin['path']}: {$origin['message']}", $origins);

        return $this->record($input, 'unavailable') + [
            'attempted_at' => $attemptedAt,
            'reason' => $changedOrigins === [] ? 'missing_upstream' : 'hash_mismatch',
            'expected_sha256' => $input->sha256,
            'expected_byte_size' => $input->byteSize,
        ] + $observed + ['message' => implode('; ', $messages), 'origins' => $origins];
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
        $partialDirectory = $this->cacheDirectory.'/partial';
        $this->createDirectory($partialDirectory);
        $this->createDirectory(dirname($path));

        $partialPath = tempnam($partialDirectory, 'acquisition-');
        if ($partialPath === false || file_put_contents($partialPath, $content) !== strlen($content) || ! rename($partialPath, $path)) {
            throw new RuntimeException("Unable to write {$path} to the input cache");
        }
    }

    private function createDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the cache directory {$directory}");
        }
    }
}
