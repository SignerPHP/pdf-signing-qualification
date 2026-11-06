<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class ExpectedRejectionsSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/expected-rejections.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'rejections' => [[
                'source_id' => 'qpdf:qpdf/qtest/qpdf/bad1.pdf',
                'scenario_id' => 'basic-invisible-sha256',
                'reason' => 'Not a PDF: five bytes of text',
            ]],
        ];
    }

    public function test_configuration_without_declarations_is_accepted(): void
    {
        self::assertValid(self::mutate(function (array $configuration): array {
            $configuration['rejections'] = [];

            return $configuration;
        }));
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'missing rejections' => [self::mutate(function (array $configuration): array {
            unset($configuration['rejections']);

            return $configuration;
        })];
        foreach (['source_id', 'scenario_id', 'reason'] as $field) {
            yield "declaration without {$field}" => [self::mutate(function (array $configuration) use ($field): array {
                unset($configuration['rejections'][0][$field]);

                return $configuration;
            })];
        }
        yield 'empty reason' => [self::mutate(function (array $configuration): array {
            $configuration['rejections'][0]['reason'] = '';

            return $configuration;
        })];
        yield 'declaration with a password' => [self::mutate(function (array $configuration): array {
            $configuration['rejections'][0]['password'] = 'secret';

            return $configuration;
        })];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $configuration): void
    {
        self::assertInvalid($configuration);
    }
}
