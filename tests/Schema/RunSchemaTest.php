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

    public function test_unidentified_container_image_is_accepted_with_its_reason(): void
    {
        self::assertValid(self::mutate(function (array $run): array {
            $run['environment']['container_image'] = ['status' => 'unavailable', 'reason' => 'QUALIFICATION_IMAGE_ID was not set'];

            return $run;
        }));
    }

    public static function invalidRuns(): iterable
    {
        yield 'run id with a space' => [self::mutate(function (array $run): array {
            $run['id'] = 'run 1';

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
