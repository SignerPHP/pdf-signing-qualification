<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class MeasurementSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v2/measurement.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'attempt_id' => 'run-20261109T120000Z/basic-invisible-sha256/jsignpdf/qpdf-test-files:qpdf/qtest/qpdf/minimal.pdf',
            'boundary' => 'process_tree',
            'instrumented' => false,
            'execution_outcome' => 'succeeded',
            'metrics' => [
                'wall_time_seconds' => ['status' => 'available', 'value' => 1.284],
                'cpu_user_seconds' => ['status' => 'available', 'value' => 2.31],
                'cpu_system_seconds' => ['status' => 'available', 'value' => 0.19],
                'peak_memory_bytes' => ['status' => 'available', 'value' => 187_412_480],
            ],
        ];
    }

    public function test_timed_out_execution_keeps_what_was_measured(): void
    {
        self::assertValid(self::mutate(function (array $measurement): array {
            $measurement['execution_outcome'] = 'timed_out';

            return $measurement;
        }));
    }

    public function test_metric_that_could_not_be_read_is_unavailable_with_its_reason(): void
    {
        self::assertValid(self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes'] = ['status' => 'unavailable', 'reason' => 'The measurement process wrote no measurement'];

            return $measurement;
        }));
    }

    public static function invalidMeasurements(): iterable
    {
        yield 'missing execution outcome' => [self::mutate(function (array $measurement): array {
            unset($measurement['execution_outcome']);

            return $measurement;
        })];
        yield 'attempt that did not run' => [self::mutate(function (array $measurement): array {
            $measurement['execution_outcome'] = 'not_run';

            return $measurement;
        })];
        yield 'unavailable metric recorded as zero' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes'] = ['status' => 'unavailable', 'reason' => 'Not read', 'value' => 0];

            return $measurement;
        })];
        yield 'unavailable metric without reason' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['cpu_user_seconds'] = ['status' => 'unavailable'];

            return $measurement;
        })];
        yield 'fractional byte count' => [self::mutate(function (array $measurement): array {
            $measurement['metrics']['peak_memory_bytes'] = ['status' => 'available', 'value' => 1024.5];

            return $measurement;
        })];
        yield 'missing boundary' => [self::mutate(function (array $measurement): array {
            unset($measurement['boundary']);

            return $measurement;
        })];
        yield 'version 1 identifier' => [self::mutate(function (array $measurement): array {
            $measurement['schema'] = self::SCHEMA_PREFIX.'v1/measurement.schema.json';

            return $measurement;
        })];
    }

    #[DataProvider('invalidMeasurements')]
    public function test_invalid_measurement_is_rejected(array $measurement): void
    {
        self::assertInvalid($measurement);
    }
}
