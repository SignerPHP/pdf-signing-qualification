<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class AcquisitionSchemaTest extends SchemaTestCase
{
    private const string EXPECTED_SHA256 = '3b4c1f0a9e6d2b7c8f5e4a3d2c1b0a9f8e7d6c5b4a39281706f5e4d3c2b1a0f9';

    private const string OBSERVED_SHA256 = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected static function schemaPath(): string
    {
        return 'v1/acquisition.schema.json';
    }

    protected static function validDocument(): array
    {
        return [
            'schema' => self::schemaId(),
            'source_id' => 'pdfjs:test/pdfs/issue17069.pdf',
            'source' => [
                'id' => 'pdfjs',
                'url' => 'https://github.com/mozilla/pdf.js',
                'revision' => ['kind' => 'commit', 'value' => '4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b'],
                'license' => [
                    'expression' => 'Apache-2.0',
                    'evidence_url' => 'https://github.com/mozilla/pdf.js/blob/4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b/LICENSE',
                ],
            ],
            'path' => 'test/pdfs/issue17069.pdf',
            'file_url' => 'https://raw.githubusercontent.com/mozilla/pdf.js/4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b/test/pdfs/issue17069.pdf',
            'status' => 'available',
            'retrieved_at' => '2026-10-29T12:00:00Z',
            'sha256' => self::EXPECTED_SHA256,
            'byte_size' => 18_423,
            'license' => [
                'expression' => 'NOASSERTION',
                'evidence_url' => 'https://github.com/mozilla/pdf.js/issues/17069',
            ],
        ];
    }

    private static function unavailable(string $reason): array
    {
        return self::mutate(function (array $acquisition) use ($reason): array {
            unset($acquisition['retrieved_at'], $acquisition['sha256'], $acquisition['byte_size'], $acquisition['license']);

            return [
                ...$acquisition,
                'status' => 'unavailable',
                'attempted_at' => '2026-10-29T12:00:00Z',
                'reason' => $reason,
                'expected_sha256' => self::EXPECTED_SHA256,
                'expected_byte_size' => 18_423,
                'message' => 'Upstream file could not be acquired as declared',
            ];
        });
    }

    private static function hashMismatch(): array
    {
        return self::mutate(function (array $acquisition): array {
            $acquisition['observed_sha256'] = self::OBSERVED_SHA256;
            $acquisition['observed_byte_size'] = 18_424;

            return $acquisition;
        }, self::unavailable('hash_mismatch'));
    }

    public static function validAcquisitions(): iterable
    {
        yield 'missing upstream file' => [self::unavailable('missing_upstream')];
        yield 'hash mismatch with the observed hash and size' => [self::hashMismatch()];
        yield 'input whose path has spaces' => [self::mutate(function (array $acquisition): array {
            $acquisition['source_id'] = 'verapdf-corpus:PDF_UA-1/7.16 Security/7.16-t01-fail-a.pdf';
            $acquisition['source']['id'] = 'verapdf-corpus';
            $acquisition['path'] = 'PDF_UA-1/7.16 Security/7.16-t01-fail-a.pdf';

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
        foreach (['retrieved_at', 'sha256', 'byte_size', 'license'] as $field) {
            yield "available input without {$field}" => [self::mutate(function (array $acquisition) use ($field): array {
                unset($acquisition[$field]);

                return $acquisition;
            })];
        }
        yield 'retrieval time without UTC designator' => [self::mutate(function (array $acquisition): array {
            $acquisition['retrieved_at'] = '2026-10-29T09:00:00-03:00';

            return $acquisition;
        })];
        yield 'source without license' => [self::mutate(function (array $acquisition): array {
            unset($acquisition['source']['license']);

            return $acquisition;
        })];
        yield 'available input carrying an unavailability reason' => [self::mutate(function (array $acquisition): array {
            $acquisition['reason'] = 'hash_mismatch';

            return $acquisition;
        })];
        yield 'unavailable input carrying a hash as if acquired' => [self::mutate(function (array $acquisition): array {
            $acquisition['sha256'] = self::OBSERVED_SHA256;

            return $acquisition;
        }, self::hashMismatch())];
        yield 'unknown unavailability reason' => [self::unavailable('network_error')];
        yield 'hash mismatch without the observed hash' => [self::mutate(function (array $acquisition): array {
            unset($acquisition['observed_sha256']);

            return $acquisition;
        }, self::hashMismatch())];
        yield 'hash mismatch without the observed size' => [self::mutate(function (array $acquisition): array {
            unset($acquisition['observed_byte_size']);

            return $acquisition;
        }, self::hashMismatch())];
        yield 'missing upstream file with an observed hash' => [self::mutate(function (array $acquisition): array {
            $acquisition['observed_sha256'] = self::OBSERVED_SHA256;

            return $acquisition;
        }, self::unavailable('missing_upstream'))];
        yield 'unavailable input without message' => [self::mutate(function (array $acquisition): array {
            unset($acquisition['message']);

            return $acquisition;
        }, self::unavailable('missing_upstream'))];
    }

    #[DataProvider('invalidAcquisitions')]
    public function test_invalid_acquisition_is_rejected(array $acquisition): void
    {
        self::assertInvalid($acquisition);
    }
}
