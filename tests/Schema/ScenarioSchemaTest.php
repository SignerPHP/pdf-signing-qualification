<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class ScenarioSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v2/scenario.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'basic-visible-sha256',
            'description' => 'Visible signature with SHA-256, no timestamp and no revocation data',
            'operation' => 'sign',
            'signing' => [
                'digest_algorithm' => 'sha256',
                'signature_algorithm' => 'rsa-pkcs1-v1_5',
                'sub_filter' => 'adbe.pkcs7.detached',
                'appearance' => 'visible',
                'placement' => ['page' => 1, 'left' => 36, 'top' => 36, 'width' => 240, 'height' => 84],
                'timestamp' => false,
                'revocation_info' => false,
            ],
        ];
    }

    public function test_invisible_scenario_without_placement_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $scenario): array {
            $scenario['signing']['appearance'] = 'invisible';
            unset($scenario['signing']['placement']);

            return $scenario;
        }));
    }

    public function test_fractional_placement_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $scenario): array {
            $scenario['signing']['placement']['left'] = 36.5;

            return $scenario;
        }));
    }

    public static function invalidScenarios(): iterable
    {
        yield 'visible without placement' => [self::mutate(function (array $scenario): array {
            unset($scenario['signing']['placement']);

            return $scenario;
        })];
        yield 'invisible with placement' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['appearance'] = 'invisible';

            return $scenario;
        })];
        yield 'page zero' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['placement']['page'] = 0;

            return $scenario;
        })];
        yield 'fractional page' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['placement']['page'] = 1.5;

            return $scenario;
        })];
        yield 'negative left' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['placement']['left'] = -1;

            return $scenario;
        })];
        yield 'empty width' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['placement']['width'] = 0;

            return $scenario;
        })];
        yield 'missing height' => [self::mutate(function (array $scenario): array {
            unset($scenario['signing']['placement']['height']);

            return $scenario;
        })];
        yield 'unknown placement property' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['placement']['rotation'] = 90;

            return $scenario;
        })];
        yield 'unknown appearance' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['appearance'] = 'hidden';

            return $scenario;
        })];
        yield 'version 1 identifier' => [self::mutate(function (array $scenario): array {
            $scenario['schema'] = self::SCHEMA_PREFIX.'v1/scenario.schema.json';

            return $scenario;
        })];
    }

    #[DataProvider('invalidScenarios')]
    public function test_invalid_scenario_is_rejected(array $scenario): void
    {
        self::assertInvalid($scenario);
    }
}
