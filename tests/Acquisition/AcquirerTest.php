<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Acquisition;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Acquisition\Source;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class AcquirerTest extends TestCase
{
    private const string CONTENT = "%PDF-1.7\nsynthetic acquisition test content\n%%EOF\n";

    private const string REVISION = '4a3b2c1d0e9f8a7b6c5d4e3f2a1b0c9d8e7f6a5b';

    private const string FILE_URL = 'https://raw.githubusercontent.com/mozilla/pdf.js/'.self::REVISION.'/test/pdfs/issue17069.pdf';

    private string $cacheDirectory;

    protected function setUp(): void
    {
        $this->cacheDirectory = sys_get_temp_dir().'/acquirer-test-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDirectory)) {
            exec('rm -rf '.escapeshellarg($this->cacheDirectory));
        }
    }

    private static function input(string $content = self::CONTENT, string $path = 'test/pdfs/issue17069.pdf'): Input
    {
        return new Input(
            source: new Source(
                id: 'pdfjs',
                url: 'https://github.com/mozilla/pdf.js',
                revision: ['kind' => 'commit', 'value' => self::REVISION],
                fileUrlTemplate: 'https://raw.githubusercontent.com/mozilla/pdf.js/{revision}/{path}',
                license: [
                    'expression' => 'Apache-2.0',
                    'evidence_url' => 'https://github.com/mozilla/pdf.js/blob/'.self::REVISION.'/LICENSE',
                ],
            ),
            path: $path,
            sha256: hash('sha256', $content),
            byteSize: strlen($content),
            license: [
                'expression' => 'NOASSERTION',
                'evidence_url' => 'https://github.com/mozilla/pdf.js/issues/17069',
            ],
        );
    }

    private function acquirer(InMemoryFetcher $fetcher, string $now = '2026-10-29T12:00:00Z'): Acquirer
    {
        return new Acquirer($fetcher, $this->cacheDirectory, static fn (): DateTimeImmutable => new DateTimeImmutable($now));
    }

    private function cachedPath(string $path = 'test/pdfs/issue17069.pdf'): string
    {
        return $this->cacheDirectory.'/files/pdfjs/'.$path;
    }

    private function cachedRecordPath(): string
    {
        return $this->cacheDirectory.'/records/pdfjs/test/pdfs/issue17069.pdf';
    }

    private static function assertValidRecord(array $record): void
    {
        $errors = (new SchemaValidator)->errors($record, Acquirer::RECORD_SCHEMA);

        self::assertSame([], $errors);
    }

    public function test_acquires_a_verified_input_into_the_cache(): void
    {
        $record = $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]))->acquire(self::input());

        self::assertValidRecord($record);
        self::assertSame('available', $record['status']);
        self::assertSame('pdfjs:test/pdfs/issue17069.pdf', $record['source_id']);
        self::assertSame(self::FILE_URL, $record['file_url']);
        self::assertSame('2026-10-29T12:00:00Z', $record['retrieved_at']);
        self::assertSame(hash('sha256', self::CONTENT), $record['sha256']);
        self::assertSame(strlen(self::CONTENT), $record['byte_size']);
        self::assertSame('Apache-2.0', $record['source']['license']['expression']);
        self::assertSame('NOASSERTION', $record['license']['expression']);
        self::assertSame(self::CONTENT, file_get_contents($this->cachedPath()));
    }

    public function test_time_is_recorded_in_utc(): void
    {
        $record = $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]), '2026-10-29T09:00:00-03:00')->acquire(self::input());

        self::assertSame('2026-10-29T12:00:00Z', $record['retrieved_at']);
    }

    public function test_hash_mismatch_is_recorded_and_nothing_is_cached(): void
    {
        $upstreamContent = self::CONTENT.'changed upstream';

        $record = $this->acquirer(new InMemoryFetcher([self::FILE_URL => $upstreamContent]))->acquire(self::input());

        self::assertValidRecord($record);
        self::assertSame('unavailable', $record['status']);
        self::assertSame('hash_mismatch', $record['reason']);
        self::assertSame(hash('sha256', self::CONTENT), $record['expected_sha256']);
        self::assertSame(hash('sha256', $upstreamContent), $record['observed_sha256']);
        self::assertSame(strlen($upstreamContent), $record['observed_byte_size']);
        self::assertFileDoesNotExist($this->cachedPath());
    }

    public function test_byte_size_differing_from_the_declaration_is_a_mismatch(): void
    {
        $declared = self::input();
        $wrongSize = new Input($declared->source, $declared->path, $declared->sha256, $declared->byteSize + 1, $declared->license);

        $record = $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]))->acquire($wrongSize);

        self::assertValidRecord($record);
        self::assertSame('hash_mismatch', $record['reason']);
        self::assertSame(strlen(self::CONTENT), $record['observed_byte_size']);
        self::assertFileDoesNotExist($this->cachedPath());
    }

    public function test_mismatch_removes_a_previously_cached_file_of_the_same_identity(): void
    {
        $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]))->acquire(self::input());
        $redeclared = self::input(self::CONTENT.'new expectation');

        $record = $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]))->acquire($redeclared);

        self::assertSame('hash_mismatch', $record['reason']);
        self::assertFileDoesNotExist($this->cachedPath());
    }

    public function test_missing_upstream_file_is_recorded_and_nothing_is_cached(): void
    {
        $record = $this->acquirer(new InMemoryFetcher)->acquire(self::input());

        self::assertValidRecord($record);
        self::assertSame('unavailable', $record['status']);
        self::assertSame('missing_upstream', $record['reason']);
        self::assertSame('2026-10-29T12:00:00Z', $record['attempted_at']);
        self::assertArrayNotHasKey('observed_sha256', $record);
        self::assertStringContainsString('404', $record['message']);
        self::assertFileDoesNotExist($this->cachedPath());
    }

    public function test_unreachable_upstream_is_not_recorded_as_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection to');

        $this->acquirer(new InMemoryFetcher(unreachableUrls: [self::FILE_URL]))->acquire(self::input());
    }

    public function test_verified_cache_is_reused_with_its_original_retrieval_time(): void
    {
        $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]))->acquire(self::input());
        $offline = new InMemoryFetcher;

        $record = $this->acquirer($offline, '2026-10-30T08:00:00Z')->acquire(self::input());

        self::assertSame([], $offline->requestedUrls);
        self::assertSame('available', $record['status']);
        self::assertSame('2026-10-29T12:00:00Z', $record['retrieved_at']);
    }

    public function test_corrupted_cache_is_acquired_again(): void
    {
        $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]))->acquire(self::input());
        file_put_contents($this->cachedPath(), 'corrupted');
        $fetcher = new InMemoryFetcher([self::FILE_URL => self::CONTENT]);

        $record = $this->acquirer($fetcher, '2026-10-30T08:00:00Z')->acquire(self::input());

        self::assertSame([self::FILE_URL], $fetcher->requestedUrls);
        self::assertSame('2026-10-30T08:00:00Z', $record['retrieved_at']);
        self::assertSame(self::CONTENT, file_get_contents($this->cachedPath()));
    }

    public function test_inputs_whose_names_extend_another_keep_separate_cache_entries(): void
    {
        $paths = ['test/pdfs/a.pdf', 'test/pdfs/a.pdf.acquisition.json', 'test/pdfs/a.pdf.json', 'test/pdfs/a.pdf.part'];
        $filesByUrl = [];
        foreach ($paths as $path) {
            $filesByUrl['https://raw.githubusercontent.com/mozilla/pdf.js/'.self::REVISION.'/'.$path] = "content of {$path}";
        }
        $online = $this->acquirer(new InMemoryFetcher($filesByUrl));
        foreach ($paths as $path) {
            $online->acquire(self::input("content of {$path}", $path));
        }
        $offline = new InMemoryFetcher;

        foreach ($paths as $path) {
            $record = $this->acquirer($offline)->acquire(self::input("content of {$path}", $path));

            self::assertSame('available', $record['status'], $path);
            self::assertSame("content of {$path}", file_get_contents($this->cachedPath($path)), $path);
        }
        self::assertSame([], $offline->requestedUrls);
    }

    public static function tamperedRecords(): iterable
    {
        yield 'byte size as a string' => [static function (array $record): array {
            $record['byte_size'] = (string) $record['byte_size'];

            return $record;
        }];
        yield 'retrieval time removed' => [static function (array $record): array {
            unset($record['retrieved_at']);

            return $record;
        }];
        yield 'unknown field added' => [static function (array $record): array {
            $record['fallback_url'] = 'https://example.org/other.pdf';

            return $record;
        }];
        yield 'document license changed' => [static function (array $record): array {
            $record['license']['expression'] = 'CC0-1.0';

            return $record;
        }];
    }

    /**
     * @param  callable(array): array  $tamper
     */
    #[DataProvider('tamperedRecords')]
    public function test_cached_record_that_does_not_match_is_acquired_again(callable $tamper): void
    {
        $this->acquirer(new InMemoryFetcher([self::FILE_URL => self::CONTENT]))->acquire(self::input());
        $storedRecord = json_decode((string) file_get_contents($this->cachedRecordPath()), true);
        self::assertIsArray($storedRecord);
        file_put_contents($this->cachedRecordPath(), json_encode($tamper($storedRecord)));
        $fetcher = new InMemoryFetcher([self::FILE_URL => self::CONTENT]);

        $record = $this->acquirer($fetcher, '2026-10-30T08:00:00Z')->acquire(self::input());

        self::assertSame([self::FILE_URL], $fetcher->requestedUrls);
        self::assertValidRecord($record);
        self::assertSame('2026-10-30T08:00:00Z', $record['retrieved_at']);
    }

    public function test_path_is_url_encoded_in_the_file_url(): void
    {
        $encodedUrl = 'https://raw.githubusercontent.com/mozilla/pdf.js/'.self::REVISION.'/test/pdfs/a%2Bb.pdf';

        $record = $this->acquirer(new InMemoryFetcher([$encodedUrl => self::CONTENT]))->acquire(self::input(path: 'test/pdfs/a+b.pdf'));

        self::assertSame('available', $record['status']);
        self::assertSame($encodedUrl, $record['file_url']);
    }
}
