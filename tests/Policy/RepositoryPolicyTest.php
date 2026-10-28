<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Policy;

use PHPUnit\Framework\TestCase;

final class RepositoryPolicyTest extends TestCase
{
    private const SYNTHETIC_FIXTURES_DIRECTORY = 'tests/Fixtures/Synthetic';

    private const SYNTHETIC_FIXTURES_OWNERSHIP_FILE = 'tests/Fixtures/Synthetic/README.md';

    private const UNSCANNED_DIRECTORIES = ['.git', 'vendor', 'var'];

    private const PRIVATE_KEY_EXTENSIONS = ['key', 'p12', 'pfx'];

    public function test_pdf_files_exist_only_as_synthetic_fixtures(): void
    {
        $misplacedPdfs = array_values(array_filter(
            $this->repositoryFiles(),
            static fn (string $path): bool => self::hasExtension($path, ['pdf'])
                && ! str_starts_with($path, self::SYNTHETIC_FIXTURES_DIRECTORY.'/'),
        ));

        self::assertSame([], $misplacedPdfs, 'PDFs outside '.self::SYNTHETIC_FIXTURES_DIRECTORY.' are not allowed; see CONTRIBUTING.md.');
    }

    public function test_every_synthetic_fixture_has_documented_ownership(): void
    {
        $ownership = (string) file_get_contents($this->repositoryRoot().'/'.self::SYNTHETIC_FIXTURES_OWNERSHIP_FILE);

        $undocumentedFixtures = array_values(array_filter(
            $this->repositoryFiles(),
            static fn (string $path): bool => str_starts_with($path, self::SYNTHETIC_FIXTURES_DIRECTORY.'/')
                && $path !== self::SYNTHETIC_FIXTURES_OWNERSHIP_FILE
                && ! str_contains($ownership, '`'.basename($path).'`'),
        ));

        self::assertSame([], $undocumentedFixtures, 'Every synthetic fixture must be listed in '.self::SYNTHETIC_FIXTURES_OWNERSHIP_FILE.'.');
    }

    public function test_no_private_key_material_is_committed(): void
    {
        $root = $this->repositoryRoot();

        $privateKeyFiles = array_values(array_filter(
            $this->repositoryFiles(),
            static fn (string $path): bool => self::hasExtension($path, self::PRIVATE_KEY_EXTENSIONS)
                || (self::hasExtension($path, ['pem', 'crt', 'cer'])
                    && str_contains((string) file_get_contents($root.'/'.$path), 'PRIVATE KEY')),
        ));

        self::assertSame([], $privateKeyFiles, 'Private keys must not be committed; see CONTRIBUTING.md.');
    }

    /**
     * @return list<string>
     */
    private function repositoryFiles(): array
    {
        $paths = $this->filesUnder('');

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $relativeDirectory): array
    {
        $paths = [];

        foreach (scandir($this->repositoryRoot().'/'.$relativeDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relativePath = ltrim($relativeDirectory.'/'.$entry, '/');

            if (! is_dir($this->repositoryRoot().'/'.$relativePath)) {
                $paths[] = $relativePath;
            } elseif (! in_array($relativePath, self::UNSCANNED_DIRECTORIES, true)) {
                array_push($paths, ...$this->filesUnder($relativePath));
            }
        }

        return $paths;
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param  list<string>  $extensions
     */
    private static function hasExtension(string $path, array $extensions): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true);
    }
}
