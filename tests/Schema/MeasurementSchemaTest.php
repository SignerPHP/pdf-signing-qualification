<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class MeasurementSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/measurement.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'attempt_id' => 'run-20261028T120000Z/pdfjs-issue17069/basic-invisible-sha256/pdf-signer/1',
            'boundary' => 'process_tree',
            'instrumented' => false,
            'metrics' => [
                'wall_time_seconds' => ['status' => 'available', 'value' => 0.412],
                'cpu_user_seconds' => ['status' => 'available', 'value' => 0.31],
                'cpu_system_seconds' => ['status' => 'available', 'value' => 0.04],
                'peak_memory_bytes' => [
                    'status' => 'unavailable',
                    'reason' => 'The runner cannot read the peak resident set size of the process tree',
                ],
            ],
        ];
    }

    public function test_measured_zero_is_accepted_as_an_available_value(): void
    {
        self::assertValid(self::mutate(function (array $measurement): array {
            $measurement['metrics']['cpu_system_seconds'] = ['status' => 'available', 'value' => 0];

            return $measurement;
        }));
    }

    public static function invalidMeasurements(): iterable
    {
        yield 'unavailable metric recorded as zero' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes']['value'] = 0;

            return $measurement;
        })];
        yield 'unavailable metric without reason' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes'] = ['status' => 'unavailable'];

            return $measurement;
        })];
        yield 'bare zero instead of a metric' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes'] = 0;

            return $measurement;
        })];
        yield 'null instead of a metric' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes'] = null;

            return $measurement;
        })];
        yield 'missing metric' => [self::mutate(function (array $measurement): array {
            unset($measurement['metrics']['peak_memory_bytes']);

            return $measurement;
        })];
        yield 'available metric without value' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['wall_time_seconds'] = ['status' => 'available'];

            return $measurement;
        })];
        yield 'negative value' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['wall_time_seconds']['value'] = -0.1;

            return $measurement;
        })];
        yield 'fractional byte count' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes'] = ['status' => 'available', 'value' => 1024.5];

            return $measurement;
        })];
        yield 'unknown boundary' => [self::mutate(function (array $measurement): array {
            $measurement['boundary'] = 'php_allocator';

            return $measurement;
        })];
        yield 'missing instrumentation flag' => [self::mutate(function (array $measurement): array {
            unset($measurement['instrumented']);

            return $measurement;
        })];
    }

    #[DataProvider('invalidMeasurements')]
    public function test_invalid_measurement_is_rejected(array $measurement): void
    {
        self::assertInvalid($measurement);
    }
}
