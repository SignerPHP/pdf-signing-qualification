<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class ObservationSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/observation.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'id' => 'run-20261028T120000Z/pdfjs-issue17069/basic-invisible-sha256/pdf-signer/1/pdfsig',
            'attempt_id' => 'run-20261028T120000Z/pdfjs-issue17069/basic-invisible-sha256/pdf-signer/1',
            'analyzer' => ['id' => 'pdfsig', 'revision' => ['kind' => 'version', 'value' => '22.02.0']],
            'policy' => [
                'id' => 'pdfsig-no-ocsp',
                'description' => 'Signature and ByteRange check without certificate revocation lookup',
                'parameters' => ['arguments' => ['-nocert', '-no-ocsp']],
            ],
            'property' => 'cms_signature',
            'input_artifact' => [
                'sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9',
                'byte_size' => 18_423,
            ],
            'output_artifact' => [
                'sha256' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
                'byte_size' => 27_911,
            ],
            'verdict' => 'valid',
            'evidence' => [
                'summary' => 'Signature #1: Signature is Valid.',
                'exit_code' => 0,
                'raw_output' => "Digital Signature Info of: signed.pdf\nSignature #1:\n  - Signature Validation: Signature is Valid.\n",
            ],
            'observed_at' => '2026-10-28T12:00:03Z',
        ];
    }

    public static function verdicts(): iterable
    {
        yield 'valid' => ['valid'];
        yield 'invalid' => ['invalid'];
        yield 'inconclusive' => ['inconclusive'];
        yield 'not evaluated' => ['not_evaluated'];
        yield 'not applicable' => ['not_applicable'];
    }

    #[DataProvider('verdicts')]
    public function test_each_verdict_is_accepted(string $verdict): void
    {
        self::assertValid(self::mutate(function (array $observation) use ($verdict): array {
            $observation['verdict'] = $verdict;

            return $observation;
        }));
    }

    public function test_observation_without_output_is_accepted_when_it_could_not_judge_one(): void
    {
        self::assertValid(self::mutate(function (array $observation): array {
            $observation['output_artifact'] = null;
            $observation['verdict'] = 'not_evaluated';
            $observation['evidence'] = ['summary' => 'The attempt produced no output to validate'];

            return $observation;
        }));
    }

    public function test_observation_without_input_is_accepted_when_the_input_was_unavailable(): void
    {
        self::assertValid(self::mutate(function (array $observation): array {
            $observation['input_artifact'] = null;
            $observation['output_artifact'] = null;
            $observation['verdict'] = 'not_evaluated';
            $observation['evidence'] = ['summary' => 'The input could not be acquired'];

            return $observation;
        }));
    }

    public static function invalidObservations(): iterable
    {
        foreach (['analyzer', 'policy', 'input_artifact', 'output_artifact', 'verdict', 'evidence'] as $field) {
            yield 'missing '.$field => [self::mutate(function (array $observation) use ($field): array {
                unset($observation[$field]);

                return $observation;
            })];
        }
        yield 'analyzer without revision' => [self::mutate(function (array $observation): array {
            unset($observation['analyzer']['revision']);

            return $observation;
        })];
        foreach (['latest', 'main', 'system'] as $movingVersion) {
            yield 'analyzer version '.$movingVersion => [self::mutate(function (array $observation) use ($movingVersion): array {
                $observation['analyzer']['revision']['value'] = $movingVersion;

                return $observation;
            })];
        }
        yield 'verdict outside the defined set' => [self::mutate(function (array $observation): array {
            $observation['verdict'] = 'passed';

            return $observation;
        })];
        yield 'majority verdict over several analyzers' => [self::mutate(function (array $observation): array {
            $observation['analyzer'] = [
                ['id' => 'pdfsig', 'revision' => ['kind' => 'version', 'value' => '22.02.0']],
                ['id' => 'pyhanko', 'revision' => ['kind' => 'version', 'value' => '0.29.1']],
            ];

            return $observation;
        })];
        yield 'evidence without summary' => [self::mutate(function (array $observation): array {
            unset($observation['evidence']['summary']);

            return $observation;
        })];
        yield 'valid verdict without an output artifact' => [self::mutate(function (array $observation): array {
            $observation['output_artifact'] = null;

            return $observation;
        })];
        yield 'valid verdict without an input artifact' => [self::mutate(function (array $observation): array {
            $observation['input_artifact'] = null;

            return $observation;
        })];
        yield 'unknown property' => [self::mutate(function (array $observation): array {
            $observation['property'] = 'everything';

            return $observation;
        })];
    }

    #[DataProvider('invalidObservations')]
    public function test_invalid_observation_is_rejected(array $observation): void
    {
        self::assertInvalid($observation);
    }
}
