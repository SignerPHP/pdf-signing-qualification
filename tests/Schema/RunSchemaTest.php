<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class RunSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/run.schema.json';
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
            ],
            'implementation' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('f', 40)]],
            'analyzers' => [
                ['id' => 'pyhanko', 'revision' => ['kind' => 'version', 'value' => '0.29.1']],
                ['id' => 'qpdf', 'revision' => ['kind' => 'version', 'value' => '11.3.0']],
            ],
            'source_configuration' => ['sha256' => str_repeat('a', 64), 'byte_size' => 10_240],
        ];
    }

    public function test_expected_rejections_file_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
            $run['expected_rejections'] = ['sha256' => str_repeat('b', 64), 'byte_size' => 420];

            return $run;
        }));
    }

    public function test_implementation_compatibility_is_accepted_and_an_incompatibility_needs_its_reason(): void
    {
        foreach (['compatible', 'not_checked'] as $status) {
            self::assertValid(self::mutate(function (array $run) use ($status): array {
                $run['implementation_compatibility'] = ['status' => $status];

                return $run;
            }));
        }
        self::assertValid(self::mutate(function (array $run): array {
            $run['implementation_compatibility'] = ['status' => 'incompatible', 'reason' => 'pdf-signer does not list --no-default-timestamp in its help'];

            return $run;
        }));
        self::assertInvalid(self::mutate(function (array $run): array {
            $run['implementation_compatibility'] = ['status' => 'incompatible'];

            return $run;
        }));
        self::assertInvalid(self::mutate(function (array $run): array {
            $run['implementation_compatibility'] = ['status' => 'compatible', 'reason' => 'Listed every option'];

            return $run;
        }));
    }

    public function test_unidentified_container_image_is_accepted_with_its_reason(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
            $run['environment']['container_image'] = ['status' => 'unavailable', 'reason' => 'QUALIFICATION_IMAGE_ID was not set'];

            return $run;
        }));
    }

    public function test_cpu_model_is_accepted_and_an_unread_one_needs_its_reason(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
            $run['environment']['cpu_model'] = ['status' => 'available', 'name' => 'AMD Ryzen 7 3700X 8-Core Processor'];

            return $run;
        }));
        self::assertValid(self::mutate(function (array $run): array {
            $run['environment']['cpu_model'] = ['status' => 'unavailable', 'reason' => '/proc/cpuinfo has no model name'];

            return $run;
        }));
    }

    public function test_resource_limits_and_implementation_runtime_are_accepted(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
            $run['environment']['resource_limits'] = [
                'timeout_seconds' => 120.0,
                'address_space_bytes' => 1_073_741_824,
                'file_size_bytes' => 268_435_456,
                'storage_bytes' => 536_870_912,
                'open_files' => 256,
            ];
            $run['implementation_runtime'] = [
                'java_version' => '21.0.13+9-LTS',
                'java_options' => ['-Xmx512m'],
                'environment_variables' => ['MALLOC_ARENA_MAX=1'],
                'engine' => 'openpdf',
                'wrapper' => ['id' => 'jsignpdf-php', 'revision' => ['kind' => 'commit', 'value' => str_repeat('b', 40)]],
            ];

            return $run;
        }));
    }

    public static function invalidRuns(): iterable
    {
        yield 'run id with a space' => [self::mutate(function (array $run): array {
            $run['id'] = 'run 1';

            return $run;
        })];
        yield 'empty java version' => [self::mutate(function (array $run): array {
            $run['implementation_runtime'] = ['java_version' => ''];

            return $run;
        })];
        yield 'empty implementation runtime' => [self::mutate(function (array $run): array {
            $run['implementation_runtime'] = [];

            return $run;
        })];
        yield 'wrapper at a moving revision' => [self::mutate(function (array $run): array {
            $run['implementation_runtime'] = ['wrapper' => ['id' => 'jsignpdf-php', 'revision' => ['kind' => 'version', 'value' => 'latest']]];

            return $run;
        })];
        yield 'resource limits without the address space' => [self::mutate(function (array $run): array {
            $run['environment']['resource_limits'] = ['timeout_seconds' => 120.0, 'file_size_bytes' => 1, 'storage_bytes' => 1, 'open_files' => 1];

            return $run;
        })];
        yield 'moving implementation revision' => [self::mutate(function (array $run): array {
            $run['implementation']['revision'] = ['kind' => 'version', 'value' => 'latest'];

            return $run;
        })];
        yield 'analyzer without revision' => [self::mutate(function (array $run): array {
            unset($run['analyzers'][0]['revision']);

            return $run;
        })];
        yield 'no analyzer' => [self::mutate(function (array $run): array {
            $run['analyzers'] = [];

            return $run;
        })];
        yield 'container image that is not a digest' => [self::mutate(function (array $run): array {
            $run['environment']['container_image'] = ['status' => 'available', 'id' => 'pdf-signing-qualification:local'];

            return $run;
        })];
        yield 'unavailable container image without reason' => [self::mutate(function (array $run): array {
            $run['environment']['container_image'] = ['status' => 'unavailable'];

            return $run;
        })];
        yield 'unavailable CPU model without reason' => [self::mutate(function (array $run): array {
            $run['environment']['cpu_model'] = ['status' => 'unavailable'];

            return $run;
        })];
        yield 'empty CPU model' => [self::mutate(function (array $run): array {
            $run['environment']['cpu_model'] = ['status' => 'available', 'name' => ''];

            return $run;
        })];
        yield 'missing PHP version' => [self::mutate(function (array $run): array {
            unset($run['environment']['php_version']);

            return $run;
        })];
        yield 'missing operating system' => [self::mutate(function (array $run): array {
            unset($run['environment']['os']);

            return $run;
        })];
        yield 'local timestamp' => [self::mutate(function (array $run): array {
            $run['finished_at'] = '2026-10-31T09:03:10-03:00';

            return $run;
        })];
    }

    #[DataProvider('invalidRuns')]
    public function test_invalid_run_is_rejected(array $run): void
    {
        self::assertInvalid($run);
    }
}
