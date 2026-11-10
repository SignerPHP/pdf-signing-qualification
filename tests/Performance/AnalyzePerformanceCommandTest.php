<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Performance\PerformanceAnalysis;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Tests\Run\RunFixture;

final class AnalyzePerformanceCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/analyze-performance-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @return array{int, string, string}
     */
    private function analyzePerformance(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../bin/analyze-performance', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: ['PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errorOutput = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errorOutput];
    }

    private function writeStudy(int $session, float $jsignpdfWallTime = 1.0): string
    {
        return StudyFixture::write($this->directory, $session, static function (RunFixture $run, array $planned) use ($jsignpdfWallTime): void {
            $jsignpdf = $planned['implementation_id'] === 'jsignpdf';
            $run->succeeded('qpdf:a.pdf', StudyFixture::VALID)->measured('qpdf:a.pdf', $jsignpdf ? $jsignpdfWallTime : 0.2, 0.3, 0.02, 50_000_000);
            $run->failed('qpdf:b.pdf');
        });
    }

    public function test_analysis_of_two_sessions_is_written_and_printed(): void
    {
        $analysisDirectory = $this->directory.'/analysis';
        [$exitCode, $output, $errorOutput] = $this->analyzePerformance($analysisDirectory, $this->writeStudy(1), $this->writeStudy(2, 1.02));

        self::assertSame(0, $exitCode, $errorOutput);
        $analysis = json_decode((string) file_get_contents($analysisDirectory.'/performance.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([], (new SchemaValidator)->errors($analysis, PerformanceAnalysis::SCHEMA));
        self::assertSame($output, file_get_contents($analysisDirectory.'/performance.md'));
        self::assertStringContainsString('# Paired performance of `pdf-signer` and `jsignpdf`', $output);
        self::assertStringContainsString('| Excluded: Execution failed or timed out in a run | 1 |', $output);
        self::assertStringContainsString('## Session 1', $output);
        self::assertStringContainsString('## Session 2', $output);
        self::assertStringContainsString('## Repeatability', $output);
        self::assertStringContainsString('**it does**', $output);
        self::assertStringNotContainsString('not comparable', $output);
        self::assertStringContainsString('no implementation is declared faster or lighter in general', $output);
        self::assertStringContainsString('| `pdf-signer` | 4 | 8 | 4 of 8 (50.0%) | 4 of 8 (50.0%) |', $output);
    }

    public function test_existing_analysis_directory_is_not_overwritten(): void
    {
        mkdir($this->directory.'/analysis');

        [$exitCode, , $errorOutput] = $this->analyzePerformance($this->directory.'/analysis', $this->writeStudy(1));

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('already exists', $errorOutput);
    }

    public function test_incomplete_session_is_refused_without_writing_anything(): void
    {
        $study = $this->writeStudy(1);
        $controls = glob($study.'/controls/03-*.json');
        self::assertNotFalse($controls);
        unlink($controls[0]);

        [$exitCode, , $errorOutput] = $this->analyzePerformance($this->directory.'/analysis', $study);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Unable to read', $errorOutput);
        self::assertDirectoryDoesNotExist($this->directory.'/analysis');
    }

    public function test_usage_is_printed_without_a_study(): void
    {
        [$exitCode, , $errorOutput] = $this->analyzePerformance($this->directory.'/analysis');

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Usage: analyze-performance', $errorOutput);
    }
}
