<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class RunSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v3/run.schema.json';
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
            'harness' => ['status' => 'available', 'revision' => ['kind' => 'commit', 'value' => str_repeat('7', 40)], 'working_tree' => ['status' => 'clean']],
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

    public function test_harness_with_changed_tracked_files_or_unread_with_its_reason_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
            $run['harness']['working_tree'] = ['status' => 'modified', 'paths' => ['src/Run/Pilot.php', 'tests/Run/PilotTest.php']];

            return $run;
        }));
        self::assertValid(self::mutate(function (array $run): array {
            $run['harness'] = ['status' => 'unavailable', 'reason' => 'Unable to read the commit of the checkout /app: git rev-parse exited with code 128, because it is not a Git checkout or its Git directory is not in the container'];

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

    public function test_implementation_runtime_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
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

    public function test_confinement_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
            $run['environment']['confinement'] = ['mechanism' => 'landlock', 'landlock_abi' => 11, 'readable_directories' => ['/etc', '/usr'], 'writable_paths' => ['/dev/null']];

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
            unset($run['environment']['resource_limits']['address_space_bytes']);

            return $run;
        })];
        yield 'no resource limits' => [self::mutate(function (array $run): array {
            unset($run['environment']['resource_limits']);

            return $run;
        })];
        yield 'resource limits without the CPU time' => [self::mutate(function (array $run): array {
            unset($run['environment']['resource_limits']['cpu_time_seconds']);

            return $run;
        })];
        yield 'resource limits without the processes' => [self::mutate(function (array $run): array {
            unset($run['environment']['resource_limits']['processes']);

            return $run;
        })];
        yield 'no CPU time' => [self::mutate(function (array $run): array {
            $run['environment']['resource_limits']['cpu_time_seconds'] = 0;

            return $run;
        })];
        yield 'no process' => [self::mutate(function (array $run): array {
            $run['environment']['resource_limits']['processes'] = 0;

            return $run;
        })];
        yield 'fractional process count' => [self::mutate(function (array $run): array {
            $run['environment']['resource_limits']['processes'] = 1.5;

            return $run;
        })];
        yield 'version 1 identifier' => [self::mutate(function (array $run): array {
            $run['schema'] = self::SCHEMA_PREFIX.'v1/run.schema.json';

            return $run;
        })];
        yield 'version 2 identifier' => [self::mutate(function (array $run): array {
            $run['schema'] = self::SCHEMA_PREFIX.'v2/run.schema.json';

            return $run;
        })];
        yield 'missing harness' => [self::mutate(function (array $run): array {
            unset($run['harness']);

            return $run;
        })];
        yield 'harness at a branch name' => [self::mutate(function (array $run): array {
            $run['harness']['revision'] = ['kind' => 'commit', 'value' => 'main'];

            return $run;
        })];
        yield 'harness at a version' => [self::mutate(function (array $run): array {
            $run['harness']['revision'] = ['kind' => 'version', 'value' => '1.0.0'];

            return $run;
        })];
        yield 'harness without the state of its working tree' => [self::mutate(function (array $run): array {
            unset($run['harness']['working_tree']);

            return $run;
        })];
        yield 'modified working tree without its paths' => [self::mutate(function (array $run): array {
            $run['harness']['working_tree'] = ['status' => 'modified', 'paths' => []];

            return $run;
        })];
        yield 'clean working tree with paths' => [self::mutate(function (array $run): array {
            $run['harness']['working_tree'] = ['status' => 'clean', 'paths' => ['src/Run/Pilot.php']];

            return $run;
        })];
        yield 'unavailable harness without reason' => [self::mutate(function (array $run): array {
            $run['harness'] = ['status' => 'unavailable'];

            return $run;
        })];
        yield 'confinement without its Landlock ABI' => [self::mutate(function (array $run): array {
            $run['environment']['confinement'] = ['mechanism' => 'landlock', 'readable_directories' => [], 'writable_paths' => []];

            return $run;
        })];
        yield 'confinement on a Landlock ABI that does not control truncation' => [self::mutate(function (array $run): array {
            $run['environment']['confinement'] = ['mechanism' => 'landlock', 'landlock_abi' => 2, 'readable_directories' => [], 'writable_paths' => []];

            return $run;
        })];
        yield 'confinement with a relative path' => [self::mutate(function (array $run): array {
            $run['environment']['confinement'] = ['mechanism' => 'landlock', 'landlock_abi' => 11, 'readable_directories' => ['usr'], 'writable_paths' => []];

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
