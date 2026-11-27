<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class AcquisitionV2SchemaTest extends SchemaTestCase
{
    private const string EXPECTED_SHA256 = '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9';

    private const string OBSERVED_SHA256 = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected static function schemaPath(): string
    {
        return 'v2/acquisition.schema.json';
    }

    private static function source(string $id = 'pdfjs'): array
    {
        return [
            'id' => $id,
            'url' => "https://github.com/example/{$id}",
            'revision' => ['kind' => 'commit', 'value' => '4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b'],
            'license' => ['expression' => 'Apache-2.0', 'evidence_url' => "https://github.com/example/{$id}/blob/main/LICENSE"],
        ];
    }

    private static function origin(string $sourceId, string $path, string $status = 'available'): array
    {
        return [
            'source' => self::source($sourceId),
            'path' => $path,
            'file_url' => "https://raw.githubusercontent.com/example/{$sourceId}/4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b/{$path}",
            'status' => $status,
        ];
    }

    private static function missingOrigin(string $sourceId, string $path): array
    {
        return self::origin($sourceId, $path, 'unavailable') + ['reason' => 'missing_upstream', 'message' => 'The file returned HTTP 404'];
    }

    private static function changedOrigin(string $sourceId, string $path): array
    {
        return self::origin($sourceId, $path, 'unavailable') + ['reason' => 'hash_mismatch', 'observed_sha256' => self::OBSERVED_SHA256, 'observed_byte_size' => 18_424, 'message' => 'Upstream file has other bytes'];
    }

    protected static function validDocument(): array
    {
        $declaredOrigin = self::origin('pdfjs', 'test/pdfs/issue17069.pdf');

        return [
            'schema' => self::schemaId(),
            'source_id' => 'pdfjs:test/pdfs/issue17069.pdf',
            'source' => $declaredOrigin['source'],
            'path' => $declaredOrigin['path'],
            'file_url' => $declaredOrigin['file_url'],
            'status' => 'available',
            'origins' => [$declaredOrigin],
            'retrieved_at' => '2026-10-29T12:00:00Z',
            'sha256' => self::EXPECTED_SHA256,
            'byte_size' => 18_423,
            'license' => [
                'expression' => 'NOASSERTION',
                'evidence_url' => 'https://github.com/mozilla/pdf.js/issues/17069',
                'redistribution_restrictions' => [],
            ],
        ];
    }

    private static function unavailable(string $reason, array $origins): array
    {
        return self::mutate(function (array $acquisition) use ($reason, $origins): array {
            unset($acquisition['retrieved_at'], $acquisition['sha256'], $acquisition['byte_size'], $acquisition['license']);
            $acquisition = [
                ...$acquisition,
                'status' => 'unavailable',
                'origins' => $origins,
                'attempted_at' => '2026-10-29T12:00:00Z',
                'reason' => $reason,
                'expected_sha256' => self::EXPECTED_SHA256,
                'expected_byte_size' => 18_423,
                'message' => 'No origin yielded the declared bytes',
            ];
            if ($reason === 'hash_mismatch') {
                $acquisition['observed_sha256'] = self::OBSERVED_SHA256;
                $acquisition['observed_byte_size'] = 18_424;
            }

            return $acquisition;
        });
    }

    public static function validAcquisitions(): iterable
    {
        yield 'available from a further origin while the declared one changed upstream' => [self::mutate(function (array $acquisition): array {
            $acquisition['origins'] = [self::changedOrigin('pdfjs', 'test/pdfs/issue17069.pdf'), self::origin('mirror', 'issue17069.pdf')];

            return $acquisition;
        })];
        yield 'available from the declared origin while a further one is missing' => [self::mutate(function (array $acquisition): array {
            $acquisition['origins'][] = self::missingOrigin('mirror', 'issue17069.pdf');

            return $acquisition;
        })];
        yield 'every origin missing' => [self::unavailable('missing_upstream', [self::missingOrigin('pdfjs', 'test/pdfs/issue17069.pdf'), self::missingOrigin('mirror', 'issue17069.pdf')])];
        yield 'one origin missing and another changed' => [self::unavailable('hash_mismatch', [self::missingOrigin('pdfjs', 'test/pdfs/issue17069.pdf'), self::changedOrigin('mirror', 'issue17069.pdf')])];
        yield 'redistribution restriction' => [self::mutate(function (array $acquisition): array {
            $acquisition['license']['redistribution_restrictions'] = [['description' => 'Only within test suites of PDF software', 'evidence_url' => 'https://example.org/terms']];

            return $acquisition;
        })];
    }

    #[DataProvider('validAcquisitions')]
    public function test_valid_acquisition_is_accepted(array $acquisition): void
    {
        self::assertValid($acquisition);
    }

    public static function invalidAcquisitions(): iterable
    {
        foreach (['origins', 'retrieved_at', 'sha256', 'byte_size', 'license'] as $field) {
            yield "available input without {$field}" => [self::mutate(function (array $acquisition) use ($field): array {
                unset($acquisition[$field]);

                return $acquisition;
            })];
        }
        yield 'no origin' => [self::mutate(function (array $acquisition): array {
            $acquisition['origins'] = [];

            return $acquisition;
        })];
        yield 'available input without an available origin' => [self::mutate(function (array $acquisition): array {
            $acquisition['origins'] = [self::changedOrigin('pdfjs', 'test/pdfs/issue17069.pdf')];

            return $acquisition;
        })];
        yield 'unavailable input with an available origin' => [self::unavailable('missing_upstream', [self::origin('pdfjs', 'test/pdfs/issue17069.pdf')])];
        yield 'document license without its redistribution restrictions' => [self::mutate(function (array $acquisition): array {
            unset($acquisition['license']['redistribution_restrictions']);

            return $acquisition;
        })];
        yield 'available origin carrying a reason' => [self::mutate(function (array $acquisition): array {
            $acquisition['origins'][0]['reason'] = 'hash_mismatch';

            return $acquisition;
        })];
        yield 'unavailable origin without reason' => [self::mutate(function (array $acquisition): array {
            $origin = self::missingOrigin('mirror', 'issue17069.pdf');
            unset($origin['reason']);
            $acquisition['origins'][] = $origin;

            return $acquisition;
        })];
        yield 'changed origin without the observed hash' => [self::mutate(function (array $acquisition): array {
            $origin = self::changedOrigin('mirror', 'issue17069.pdf');
            unset($origin['observed_sha256']);
            $acquisition['origins'][] = $origin;

            return $acquisition;
        })];
        yield 'missing origin with an observed hash' => [self::mutate(function (array $acquisition): array {
            $acquisition['origins'][] = self::missingOrigin('mirror', 'issue17069.pdf') + ['observed_sha256' => self::OBSERVED_SHA256];

            return $acquisition;
        })];
        yield 'origin without its file URL' => [self::mutate(function (array $acquisition): array {
            unset($acquisition['origins'][0]['file_url']);

            return $acquisition;
        })];
        yield 'hash mismatch without the observed hash' => [self::mutate(function (array $acquisition): array {
            unset($acquisition['observed_sha256']);

            return $acquisition;
        }, self::unavailable('hash_mismatch', [self::changedOrigin('pdfjs', 'test/pdfs/issue17069.pdf')]))];
        yield 'unknown unavailability reason' => [self::mutate(function (array $acquisition): array {
            $acquisition['reason'] = 'network_error';

            return $acquisition;
        }, self::unavailable('missing_upstream', [self::missingOrigin('pdfjs', 'test/pdfs/issue17069.pdf')]))];
    }

    #[DataProvider('invalidAcquisitions')]
    public function test_invalid_acquisition_is_rejected(array $acquisition): void
    {
        self::assertInvalid($acquisition);
    }
}
