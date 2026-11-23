<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class ScenarioV3SchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v3/scenario.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'pades-baseline-lta',
            'description' => 'One PAdES baseline LTA signature by a certificate of the test PKI',
            'operation' => 'sign',
            'signing' => [
                'digest_algorithm' => 'sha256',
                'signature_algorithm' => 'rsa-pkcs1-v1_5',
                'sub_filter' => 'ETSI.CAdES.detached',
                'appearance' => 'invisible',
                'timestamp' => true,
                'revocation_info' => true,
                'document_timestamp' => true,
                'signers' => ['valid'],
            ],
        ];
    }

    public function test_two_signers_with_a_certification_level_and_a_policy_are_accepted(): void
    {
        self::assertValid(self::mutate(function (array $scenario): array {
            $scenario['signing']['signers'] = ['valid', 'valid'];
            $scenario['signing']['certification_level'] = 2;
            $scenario['signing']['signature_policy'] = 'icp-brasil-ad-rt';

            return $scenario;
        }));
    }

    public function test_scenario_level_expected_rejection_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $scenario): array {
            $scenario['signing']['signers'] = ['revoked'];
            $scenario['expected_rejection'] = ['reason' => 'The signer certificate is revoked'];

            return $scenario;
        }));
    }

    public function test_visible_scenario_with_placement_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $scenario): array {
            $scenario['signing']['appearance'] = 'visible';
            $scenario['signing']['placement'] = ['page' => 1, 'left' => 36, 'top' => 36, 'width' => 240, 'height' => 84];

            return $scenario;
        }));
    }

    public static function invalidScenarios(): iterable
    {
        $signing = static fn (callable $mutation): array => self::mutate(function (array $scenario) use ($mutation): array {
            $scenario['signing'] = $mutation($scenario['signing']);

            return $scenario;
        });

        yield 'no signers' => [$signing(function (array $signing): array {
            unset($signing['signers']);

            return $signing;
        })];
        yield 'empty signers' => [$signing(fn (array $signing): array => ['signers' => []] + $signing)];
        yield 'three signers' => [$signing(fn (array $signing): array => ['signers' => ['valid', 'valid', 'valid']] + $signing)];
        yield 'unknown certificate state' => [$signing(fn (array $signing): array => ['signers' => ['expired']] + $signing)];
        yield 'no document timestamp flag' => [$signing(function (array $signing): array {
            unset($signing['document_timestamp']);

            return $signing;
        })];
        yield 'revocation data without timestamp' => [$signing(fn (array $signing): array => ['timestamp' => false, 'document_timestamp' => false] + $signing)];
        yield 'document timestamp without revocation data' => [$signing(fn (array $signing): array => ['revocation_info' => false] + $signing)];
        yield 'certification level 4' => [$signing(fn (array $signing): array => ['certification_level' => 4] + $signing)];
        yield 'unknown signature policy' => [$signing(fn (array $signing): array => ['signature_policy' => 'icp-brasil-ad-ra'] + $signing)];
        yield 'placement of an invisible signature' => [$signing(fn (array $signing): array => ['placement' => ['page' => 1, 'left' => 0, 'top' => 0, 'width' => 1, 'height' => 1]] + $signing)];
        yield 'expected rejection without reason' => [self::mutate(fn (array $scenario): array => ['expected_rejection' => []] + $scenario)];
    }

    #[DataProvider('invalidScenarios')]
    public function test_invalid_scenario_is_rejected(array $scenario): void
    {
        self::assertInvalid($scenario);
    }
}
