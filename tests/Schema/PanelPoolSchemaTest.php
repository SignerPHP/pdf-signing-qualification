<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class PanelPoolSchemaTest extends SchemaTestCase
{
    protected static function schemaPath(): string
    {
        return 'v1/panel-pool.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'plan_sha256' => str_repeat('a', 64),
            'analyzer' => ['name' => 'qpdf', 'version' => '11.3.0'],
            'candidates' => 3,
            'selected' => 2,
            'sources' => [['id' => 'qpdf', 'candidates' => 3, 'selected' => 2, 'excluded' => [['pattern' => '*/issue-*.pdf', 'reason' => 'Reproduces a bug report', 'files' => 25]], 'unaddressable' => 14, 'unclassified' => 0, 'analyzer_failures' => [['path' => 'qpdf/qtest/qpdf/xref-compressed-in-compressed.pdf', 'reason' => 'qpdf was terminated by signal 11']]]],
            'strata' => [['stratum' => 'classic', 'candidates' => 3, 'selected' => 2], ['stratum' => 'timestamped', 'candidates' => 0, 'selected' => 0]],
        ];
    }

    public static function invalidPools(): iterable
    {
        foreach (['plan_sha256', 'analyzer', 'candidates', 'selected', 'sources', 'strata'] as $field) {
            yield "pool without {$field}" => [self::mutate(function (array $pool) use ($field): array {
                unset($pool[$field]);

                return $pool;
            })];
        }
        yield 'unknown stratum' => [self::mutate(function (array $pool): array {
            $pool['strata'][0]['stratum'] = 'broken';

            return $pool;
        })];
        yield 'another analyzer' => [self::mutate(function (array $pool): array {
            $pool['analyzer']['name'] = 'pdfsig';

            return $pool;
        })];
        yield 'negative count' => [self::mutate(function (array $pool): array {
            $pool['sources'][0]['excluded'][0]['files'] = -1;

            return $pool;
        })];
    }

    #[DataProvider('invalidPools')]
    public function test_invalid_pool_is_rejected(array $pool): void
    {
        self::assertInvalid($pool);
    }
}
