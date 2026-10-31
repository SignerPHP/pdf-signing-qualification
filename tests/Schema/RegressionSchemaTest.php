<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class RegressionSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/regression.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'hybrid-xref',
            'description' => 'Signing a hybrid-reference file keeps the previous /XRefStm in the new trailer',
            'origin' => ['issue_url' => 'https://github.com/SignerPHP/signer-php/issues/24'],
            'scenario_id' => 'basic-invisible-sha256',
            'input' => [
                'kind' => 'synthetic',
                'generator' => 'tests/Fixtures/Synthetic/HybridXrefPdf.php',
                'sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9',
                'byte_size' => 721,
            ],
            'condition' => 'The trailer of the signed revision has an /XRefStm offset that points into the original revision',
        ];
    }

    public function test_upstream_input_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $case): array {
            $case['input'] = ['kind' => 'upstream', 'source_id' => 'qpdf:qpdf/qtest/qpdf/hybrid-xref.pdf'];

            return $case;
        }));
    }

    public static function invalidCases(): iterable
    {
        yield 'missing origin' => [self::mutate(function (array $case): array {
            unset($case['origin']);

            return $case;
        })];
        yield 'origin that is not an issue' => [self::mutate(function (array $case): array {
            $case['origin']['issue_url'] = 'https://github.com/SignerPHP/signer-php/pull/25';

            return $case;
        })];
        yield 'origin over http' => [self::mutate(function (array $case): array {
            $case['origin']['issue_url'] = 'http://github.com/SignerPHP/signer-php/issues/24';

            return $case;
        })];
        yield 'missing scenario' => [self::mutate(function (array $case): array {
            unset($case['scenario_id']);

            return $case;
        })];
        yield 'synthetic input without its hash' => [self::mutate(function (array $case): array {
            unset($case['input']['sha256']);

            return $case;
        })];
        yield 'empty synthetic input' => [self::mutate(function (array $case): array {
            $case['input']['byte_size'] = 0;

            return $case;
        })];
        yield 'generator outside the synthetic fixtures' => [self::mutate(function (array $case): array {
            $case['input']['generator'] = 'src/Signing/AttemptRunner.php';

            return $case;
        })];
        yield 'generator escaping the repository' => [self::mutate(function (array $case): array {
            $case['input']['generator'] = 'tests/Fixtures/Synthetic/../../../bin/acquire-inputs.php';

            return $case;
        })];
        yield 'upstream input with a hash of its own' => [self::mutate(function (array $case): array {
            $case['input'] = ['kind' => 'upstream', 'source_id' => 'qpdf:qpdf/qtest/qpdf/hybrid-xref.pdf', 'sha256' => $case['input']['sha256']];

            return $case;
        })];
        yield 'unknown input kind' => [self::mutate(function (array $case): array {
            $case['input']['kind'] = 'reported';

            return $case;
        })];
        yield 'missing condition' => [self::mutate(function (array $case): array {
            unset($case['condition']);

            return $case;
        })];
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_case_is_rejected(array $case): void
    {
        self::assertInvalid($case);
    }
}
