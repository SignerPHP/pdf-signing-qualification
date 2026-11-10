<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class PanelSelectionSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/panel-selection.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'seed' => 'main-panel-1',
            'quota_per_stratum' => 12,
            'large' => ['byte_size' => 2_097_152, 'page_count' => 1000],
            'sources' => [
                [
                    'id' => 'qpdf',
                    'url' => 'https://github.com/qpdf/qpdf',
                    'revision' => ['kind' => 'commit', 'value' => '4eba95899886e851cc41d76886483b347612f2a8'],
                    'file_url' => 'https://raw.githubusercontent.com/qpdf/qpdf/{revision}/{path}',
                    'license' => ['expression' => 'Apache-2.0', 'evidence_url' => 'https://github.com/qpdf/qpdf/blob/4eba95899886e851cc41d76886483b347612f2a8/LICENSE.txt'],
                    'listing_url' => 'https://api.github.com/repos/qpdf/qpdf/git/trees/{revision}?recursive=1',
                    'document_license' => ['expression' => 'Apache-2.0', 'evidence_url' => 'https://sources.debian.org/src/qpdf/12.4.2-1/debian/copyright/'],
                    'include' => ['*.pdf'],
                    'exclude' => [['pattern' => '*/issue-*.pdf', 'reason' => 'Reproduces a bug report']],
                ],
            ],
            'rejected_sources' => [['url' => 'https://github.com/mozilla/pdf.js', 'reason' => 'No established license']],
        ];
    }

    public static function invalidPlans(): iterable
    {
        foreach (['seed', 'quota_per_stratum', 'large', 'sources', 'rejected_sources'] as $field) {
            yield "plan without {$field}" => [self::mutate(function (array $plan) use ($field): array {
                unset($plan[$field]);

                return $plan;
            })];
        }
        foreach (['listing_url', 'document_license', 'include', 'exclude'] as $field) {
            yield "source without {$field}" => [self::mutate(function (array $plan) use ($field): array {
                unset($plan['sources'][0][$field]);

                return $plan;
            })];
        }
        yield 'quota of zero' => [self::mutate(function (array $plan): array {
            $plan['quota_per_stratum'] = 0;

            return $plan;
        })];
        yield 'no source' => [self::mutate(function (array $plan): array {
            $plan['sources'] = [];

            return $plan;
        })];
        yield 'no include pattern' => [self::mutate(function (array $plan): array {
            $plan['sources'][0]['include'] = [];

            return $plan;
        })];
        yield 'exclusion without reason' => [self::mutate(function (array $plan): array {
            unset($plan['sources'][0]['exclude'][0]['reason']);

            return $plan;
        })];
        yield 'listing URL without revision placeholder' => [self::mutate(function (array $plan): array {
            $plan['sources'][0]['listing_url'] = 'https://api.github.com/repos/qpdf/qpdf/git/trees/main?recursive=1';

            return $plan;
        })];
        yield 'branch name as revision' => [self::mutate(function (array $plan): array {
            $plan['sources'][0]['revision'] = ['kind' => 'commit', 'value' => 'main'];

            return $plan;
        })];
        yield 'rejected source without reason' => [self::mutate(function (array $plan): array {
            unset($plan['rejected_sources'][0]['reason']);

            return $plan;
        })];
    }

    #[DataProvider('invalidPlans')]
    public function test_invalid_plan_is_rejected(array $plan): void
    {
        self::assertInvalid($plan);
    }
}
