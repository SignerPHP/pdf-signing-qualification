<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class BaselineSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/baseline.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'run-20261028T120000Z/verapdf-corpus:PDF_A-2b/veraPDF test suite 6-1-12-t01-pass-a.pdf/pdfsig',
            'run_id' => 'run-20261028T120000Z',
            'source_id' => 'verapdf-corpus:PDF_A-2b/veraPDF test suite 6-1-12-t01-pass-a.pdf',
            'analyzer' => ['id' => 'pdfsig', 'revision' => ['kind' => 'version', 'value' => '22.02.0']],
            'policy' => [
                'id' => 'pdfsig-no-certificate',
                'description' => 'Signature check without certificate validation',
                'parameters' => ['arguments' => ['-nocert']],
            ],
            'property' => 'cms_signature',
            'input_artifact' => [
                'sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9',
                'byte_size' => 18_423,
            ],
            'verdict' => 'invalid',
            'evidence' => [
                'summary' => 'pdfsig rejected a signature: Digest Mismatch.',
                'exit_code' => 0,
                'raw_output' => "Digital Signature Info of: input.pdf\nSignature #1:\n  - Signature Validation: Digest Mismatch.\n",
            ],
            'observed_at' => '2026-10-28T12:00:03Z',
        ];
    }

    public static function verdicts(): iterable
    {
        yield 'valid' => ['valid'];
        yield 'invalid' => ['invalid'];
        yield 'inconclusive' => ['inconclusive'];
        yield 'not applicable' => ['not_applicable'];
    }

    #[DataProvider('verdicts')]
    public function test_each_verdict_on_an_input_is_accepted(string $verdict): void
    {
        self::assertValid(self::mutate(function (array $baseline) use ($verdict): array {
            $baseline['verdict'] = $verdict;

            return $baseline;
        }));
    }

    public function test_not_evaluated_is_rejected_because_an_unavailable_input_has_no_baseline(): void
    {
        self::assertInvalid(self::mutate(function (array $baseline): array {
            $baseline['verdict'] = 'not_evaluated';

            return $baseline;
        }));
    }

    public function test_input_artifact_is_required(): void
    {
        self::assertInvalid(self::mutate(function (array $baseline): array {
            $baseline['input_artifact'] = null;

            return $baseline;
        }));
    }

    public function test_baseline_does_not_belong_to_an_attempt_or_an_output(): void
    {
        self::assertInvalid(self::validDocument() + ['attempt_id' => 'run-20261028T120000Z/basic-invisible-sha256/pdf-signer/qpdf:good.pdf']);
        self::assertInvalid(self::validDocument() + ['output_artifact' => null]);
    }

    public function test_evidence_needs_a_summary(): void
    {
        self::assertInvalid(self::mutate(function (array $baseline): array {
            unset($baseline['evidence']['summary']);

            return $baseline;
        }));
    }
}
