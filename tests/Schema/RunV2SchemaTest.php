<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use SignerPHP\PdfSigningQualification\Run\Pilot;

final class RunV2SchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v2/run.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'run-20261031T120000Z',
            'scenario_id' => 'basic-invisible-sha256',
            'started_at' => '2026-10-31T12:00:00Z',
            'finished_at' => '2026-10-31T12:03:10Z',
            'environment' => [
                'os' => ['name' => 'Debian GNU/Linux 12 (bookworm)', 'kernel' => '6.8.0', 'architecture' => 'x86_64'],
                'container_image' => ['status' => 'available', 'id' => 'sha256:'.str_repeat('c', 64)],
                'php_version' => '8.4.27',
                'resource_limits' => [
                    'timeout_seconds' => 120.0,
                    'cpu_time_seconds' => 240.0,
                    'address_space_bytes' => 1_073_741_824,
                    'file_size_bytes' => 268_435_456,
                    'storage_bytes' => 536_870_912,
                    'open_files' => 256,
                    'processes' => 128,
                ],
            ],
            'implementation' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('f', 40)]],
            'analyzers' => [['id' => 'qpdf', 'revision' => ['kind' => 'version', 'value' => '11.3.0']]],
            'source_configuration' => ['sha256' => str_repeat('a', 64), 'byte_size' => 10_240],
        ];
    }

    public function test_version_2_is_still_read_but_no_longer_written(): void
    {
        self::assertContains(self::schemaId(), Pilot::READABLE_RUN_SCHEMAS);
        self::assertNotSame(self::schemaId(), Pilot::RUN_SCHEMA);
    }

    public function test_version_2_does_not_hold_the_harness(): void
    {
        self::assertInvalid(self::validDocument() + ['harness' => ['status' => 'available', 'revision' => ['kind' => 'commit', 'value' => str_repeat('7', 40)], 'working_tree' => ['status' => 'clean']]]);
    }
}
