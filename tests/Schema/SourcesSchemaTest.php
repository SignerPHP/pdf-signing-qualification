<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class SourcesSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/sources.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'sources' => [
                [
                    'id' => 'pdfjs',
                    'url' => 'https://github.com/mozilla/pdf.js',
                    'revision' => ['kind' => 'commit', 'value' => '4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b'],
                    'file_url' => 'https://raw.githubusercontent.com/mozilla/pdf.js/{revision}/{path}',
                    'license' => [
                        'expression' => 'Apache-2.0',
                        'evidence_url' => 'https://github.com/mozilla/pdf.js/blob/4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b/LICENSE',
                    ],
                ],
            ],
            'inputs' => [
                [
                    'source' => 'pdfjs',
                    'path' => 'test/pdfs/issue17069.pdf',
                    'sha256' => '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9',
                    'byte_size' => 18_423,
                    'license' => [
                        'expression' => 'NOASSERTION',
                        'evidence_url' => 'https://github.com/mozilla/pdf.js/issues/17069',
                    ],
                ],
            ],
        ];
    }

    public static function validConfigurations(): iterable
    {
        yield 'no inputs selected yet' => [self::mutate(function (array $configuration): array {
            $configuration['sources'] = [];
            $configuration['inputs'] = [];

            return $configuration;
        })];
        yield 'compound SPDX expression' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['license']['expression'] = '(MIT OR Apache-2.0) AND GPL-2.0-or-later WITH Classpath-exception-2.0';

            return $configuration;
        })];
        yield 'released version as revision' => [self::mutate(function (array $configuration): array {
            $configuration['sources'][0]['revision'] = ['kind' => 'version', 'value' => 'v4.0.379'];

            return $configuration;
        })];
    }

    #[DataProvider('validConfigurations')]
    public function test_valid_configuration_is_accepted(array $configuration): void
    {
        self::assertValid($configuration);
    }

    public static function invalidConfigurations(): iterable
    {
        foreach (['id', 'url', 'revision', 'file_url', 'license'] as $field) {
            yield "source without {$field}" => [self::mutate(function (array $configuration) use ($field): array {
                unset($configuration['sources'][0][$field]);

                return $configuration;
            })];
        }
        foreach (['source', 'path', 'sha256', 'byte_size', 'license'] as $field) {
            yield "input without {$field}" => [self::mutate(function (array $configuration) use ($field): array {
                unset($configuration['inputs'][0][$field]);

                return $configuration;
            })];
        }
        yield 'branch name as revision' => [self::mutate(function (array $configuration): array {
            $configuration['sources'][0]['revision'] = ['kind' => 'commit', 'value' => 'master'];

            return $configuration;
        })];
        yield 'plain HTTP upstream' => [self::mutate(function (array $configuration): array {
            $configuration['sources'][0]['url'] = 'http://github.com/mozilla/pdf.js';

            return $configuration;
        })];
        yield 'file URL without revision placeholder' => [self::mutate(function (array $configuration): array {
            $configuration['sources'][0]['file_url'] = 'https://raw.githubusercontent.com/mozilla/pdf.js/master/{path}';

            return $configuration;
        })];
        yield 'file URL without path placeholder' => [self::mutate(function (array $configuration): array {
            $configuration['sources'][0]['file_url'] = 'https://raw.githubusercontent.com/mozilla/pdf.js/{revision}/test.pdf';

            return $configuration;
        })];
        yield 'absolute path' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['path'] = '/etc/passwd';

            return $configuration;
        })];
        yield 'path leaving the source' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['path'] = 'test/../../secret.pdf';

            return $configuration;
        })];
        yield 'abbreviated hash' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['sha256'] = '3b4c1f0a';

            return $configuration;
        })];
        yield 'negative byte size' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['byte_size'] = -1;

            return $configuration;
        })];
        yield 'free text license' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['license']['expression'] = 'same as the repository';

            return $configuration;
        })];
        yield 'empty license' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['license']['expression'] = '';

            return $configuration;
        })];
        yield 'license without evidence' => [self::mutate(function (array $configuration): array {
            unset($configuration['inputs'][0]['license']['evidence_url']);

            return $configuration;
        })];
        yield 'source identifier longer than 64 characters' => [self::mutate(function (array $configuration): array {
            $configuration['sources'][0]['id'] = str_repeat('s', 65);
            $configuration['inputs'][0]['source'] = str_repeat('s', 65);

            return $configuration;
        })];
        yield 'path longer than 447 characters' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['path'] = str_repeat('p', 444).'.pdf';

            return $configuration;
        })];
        yield 'unknown input field' => [self::mutate(function (array $configuration): array {
            $configuration['inputs'][0]['fallback_url'] = 'https://example.org/other.pdf';

            return $configuration;
        })];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $configuration): void
    {
        self::assertInvalid($configuration);
    }
}
