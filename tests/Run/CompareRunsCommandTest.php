<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\RunComparison;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class CompareRunsCommandTest extends TestCase
{
    private const string INPUT = 'qpdf:qpdf/qtest/qpdf/good17.pdf';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/compare-runs-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @return array{int, string, string}
     */
    private function compareRuns(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../bin/compare-runs', ...$arguments],
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

    private function writeRuns(RunFixture $reference, RunFixture $candidate): array
    {
        return [
            $reference->write($this->directory.'/'.$reference->run['id']),
            $candidate->write($this->directory.'/'.$candidate->run['id']),
        ];
    }

    private function readComparison(string $comparisonDirectory): array
    {
        $comparison = json_decode((string) file_get_contents($comparisonDirectory.'/comparison.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($comparison);
        self::assertSame([], (new SchemaValidator)->errors($comparison, RunComparison::SCHEMA));

        return $comparison;
    }

    public function test_identical_runs_with_different_output_hashes_exit_with_zero(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid'], 'first'),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid'], 'second'),
        );

        [$exitCode, $output, $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertSame((string) file_get_contents($this->directory.'/comparison/comparison.md'), $output);
        self::assertStringEndsWith("The candidate run introduced no failure.\n", $output);
        self::assertSame(4, $this->readComparison($this->directory.'/comparison')['totals']['changes']['unchanged']);
    }

    public function test_a_new_failure_exits_with_one(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'invalid', 'qpdf' => 'valid']),
        );

        [$exitCode, $output, $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(1, $exitCode, $errorOutput);
        self::assertStringContainsString('| `'.self::INPUT.'` | verdict of `pdfsig` | `valid` | `invalid` |', $output);
        self::assertSame(1, $this->readComparison($this->directory.'/comparison')['totals']['changes']['introduced']);
    }

    public function test_a_fixed_failure_without_a_new_one_exits_with_zero(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->failed(self::INPUT),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
        );

        [$exitCode, , $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertSame(1, $this->readComparison($this->directory.'/comparison')['totals']['changes']['fixed']);
    }

    public function test_a_run_without_run_json_is_refused(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
        );
        unlink($candidate.'/run.json');

        [$exitCode, $output, $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(2, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('has no run.json, so it holds an aborted or unfinished run', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/comparison');
    }

    public function test_an_attempt_without_an_observation_from_each_analyzer_is_refused(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
        );
        $observations = json_decode((string) file_get_contents($candidate.'/observations.json'), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($candidate.'/observations.json', json_encode(array_slice($observations, 1), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [$exitCode, , $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('does not have one observation from each analyzer of the run', $errorOutput);
    }

    public function test_a_record_that_does_not_follow_its_schema_is_refused(): void
    {
        $fixture = (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']);
        [$reference, $candidate] = $this->writeRuns((new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']), $fixture);
        $attempts = json_decode((string) file_get_contents($candidate.'/attempts.json'), true, flags: JSON_THROW_ON_ERROR);
        $attempts[0]['execution']['outcome'] = 'crashed';
        file_put_contents($candidate.'/attempts.json', json_encode($attempts, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [$exitCode, , $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Invalid attempt '.$fixture->attemptId(self::INPUT), $errorOutput);
    }

    public function test_an_existing_comparison_directory_and_wrong_arguments_are_refused(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
        );

        [$exitCode, , $errorOutput] = $this->compareRuns($reference, $candidate, $reference);
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('already exists', $errorOutput);

        [$exitCode, , $errorOutput] = $this->compareRuns($reference, $candidate);
        self::assertSame(2, $exitCode);
        self::assertStringStartsWith('Usage: compare-runs', $errorOutput);
    }
}
