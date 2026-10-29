<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Acquisition;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;

final class SourceConfigurationTest extends TestCase
{
    private const string SHA256 = '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9';

    private static function configuration(): array
    {
        return [
            'schema' => SourceConfiguration::SCHEMA,
            'sources' => [
                [
                    'id' => 'pdfjs',
                    'url' => 'https://github.com/mozilla/pdf.js',
                    'revision' => ['kind' => 'commit', 'value' => '4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b'],
                    'file_url' => 'https://raw.githubusercontent.com/mozilla/pdf.js/{revision}/{path}',
                    'license' => ['expression' => 'Apache-2.0', 'evidence_url' => 'https://github.com/mozilla/pdf.js/blob/master/LICENSE'],
                ],
            ],
            'inputs' => [
                [
                    'source' => 'pdfjs',
                    'path' => 'test/pdfs/issue17069.pdf',
                    'sha256' => self::SHA256,
                    'byte_size' => 18_423,
                    'license' => ['expression' => 'NOASSERTION', 'evidence_url' => 'https://github.com/mozilla/pdf.js/issues/17069'],
                ],
            ],
        ];
    }

    public function test_declared_inputs_are_bound_to_their_source(): void
    {
        $inputs = SourceConfiguration::fromArray(self::configuration());

        self::assertCount(1, $inputs);
        self::assertSame('pdfjs:test/pdfs/issue17069.pdf', $inputs[0]->id());
        self::assertSame('https://github.com/mozilla/pdf.js', $inputs[0]->source->url);
        self::assertSame(self::SHA256, $inputs[0]->sha256);
        self::assertSame(18_423, $inputs[0]->byteSize);
    }

    public function test_versioned_configuration_of_the_repository_is_valid(): void
    {
        SourceConfiguration::fromFile(__DIR__.'/../../config/inputs.json');

        $this->addToAssertionCount(1);
    }

    public function test_configuration_violating_the_schema_is_rejected(): void
    {
        $configuration = self::configuration();
        $configuration['sources'][0]['revision'] = ['kind' => 'commit', 'value' => 'master'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('/sources/0/revision');

        SourceConfiguration::fromArray($configuration);
    }

    public function test_input_of_an_undeclared_source_is_rejected(): void
    {
        $configuration = self::configuration();
        $configuration['inputs'][0]['source'] = 'poppler';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('undeclared source poppler');

        SourceConfiguration::fromArray($configuration);
    }

    public function test_source_declared_twice_is_rejected(): void
    {
        $configuration = self::configuration();
        $configuration['sources'][] = $configuration['sources'][0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source pdfjs is declared more than once');

        SourceConfiguration::fromArray($configuration);
    }

    public function test_input_declared_twice_is_rejected(): void
    {
        $configuration = self::configuration();
        $configuration['inputs'][] = $configuration['inputs'][0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Input pdfjs:test/pdfs/issue17069.pdf is declared more than once');

        SourceConfiguration::fromArray($configuration);
    }
}
