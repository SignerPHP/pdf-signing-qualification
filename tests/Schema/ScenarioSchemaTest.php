<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class ScenarioSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/scenario.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'basic-invisible-sha256',
            'description' => 'Invisible signature with SHA-256, no timestamp and no revocation data',
            'operation' => 'sign',
            'signing' => [
                'digest_algorithm' => 'sha256',
                'signature_algorithm' => 'rsa-pkcs1-v1_5',
                'sub_filter' => 'adbe.pkcs7.detached',
                'appearance' => 'invisible',
                'timestamp' => false,
                'revocation_info' => false,
            ],
        ];
    }

    public static function invalidScenarios(): iterable
    {
        yield 'identifier with spaces' => [self::mutate(function (array $scenario): array {
            $scenario['id'] = 'basic invisible';

            return $scenario;
        })];
        yield 'missing digest algorithm' => [self::mutate(function (array $scenario): array {
            unset($scenario['signing']['digest_algorithm']);

            return $scenario;
        })];
        yield 'unknown digest algorithm' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['digest_algorithm'] = 'md5';

            return $scenario;
        })];
        yield 'missing signature algorithm' => [self::mutate(function (array $scenario): array {
            unset($scenario['signing']['signature_algorithm']);

            return $scenario;
        })];
        yield 'unknown appearance' => [self::mutate(function (array $scenario): array {
            $scenario['signing']['appearance'] = 'hidden';

            return $scenario;
        })];
        yield 'unknown operation' => [self::mutate(function (array $scenario): array {
            $scenario['operation'] = 'encrypt';

            return $scenario;
        })];
    }

    #[DataProvider('invalidScenarios')]
    public function test_invalid_scenario_is_rejected(array $scenario): void
    {
        self::assertInvalid($scenario);
    }
}
