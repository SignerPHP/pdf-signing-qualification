<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
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
        return $this->runCommand([PHP_BINARY, __DIR__.'/../../bin/compare-runs', ...$arguments]);
    }

    /**
     * @param  list<string>  $command
     * @return array{int, string, string}
     */
    private function runCommand(array $command): array
    {
        $process = proc_open(
            $command,
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

    public function test_a_regression_condition_that_reappears_exits_with_one(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid'])->regression('hybrid-xref', 'absent', ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid'])->regression('hybrid-xref', 'present', ['pdfsig' => 'valid', 'qpdf' => 'valid']),
        );

        [$exitCode, $output, $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(1, $exitCode, $errorOutput);
        self::assertStringContainsString('| `hybrid-xref` | `paired` | `absent`: The condition of hybrid-xref is absent | `present`: The condition of hybrid-xref is present | `introduced` |', $output);
        $comparison = $this->readComparison($this->directory.'/comparison');
        self::assertSame(0, $comparison['totals']['changes']['introduced']);
        self::assertSame(1, $comparison['totals']['regression_changes']['introduced']);
    }

    /**
     * @return iterable<string, array{callable(array): array, string}>
     */
    public static function inconsistentRegressionReplays(): iterable
    {
        yield 'replay of another run' => [static function (array $regressions): array {
            $regressions['run_id'] = 'run-20261107T000000Z';

            return $regressions;
        }, 'belongs to the run run-20261107T000000Z'];
        yield 'case on an attempt that is not in the run' => [static function (array $regressions): array {
            $regressions['cases'][0]['attempt_id'] = 'run-20261107T130000Z/basic-invisible-sha256/pdf-signer/regression:missing.pdf';

            return $regressions;
        }, 'which is not in the run'];
        yield 'regression attempt without its observations' => [static function (array $regressions): array {
            $regressions['observations'] = [];

            return $regressions;
        }, 'does not have one observation from each analyzer of the run'];
        yield 'case recorded twice' => [static function (array $regressions): array {
            $regressions['cases'][] = $regressions['cases'][0];

            return $regressions;
        }, 'The regression case hybrid-xref in'];
        yield 'replay that does not follow its schema' => [static function (array $regressions): array {
            unset($regressions['cases'][0]['condition']['evidence']);

            return $regressions;
        }, 'Invalid regression replay'];
    }

    /**
     * @param  callable(array): array  $mutation
     */
    #[DataProvider('inconsistentRegressionReplays')]
    public function test_an_inconsistent_regression_replay_is_refused(callable $mutation, string $message): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid'])->regression('hybrid-xref', 'present'),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid'])->regression('hybrid-xref', 'present'),
        );
        $regressions = json_decode((string) file_get_contents($candidate.'/regressions.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($regressions);
        file_put_contents($candidate.'/regressions.json', json_encode($mutation($regressions), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [$exitCode, , $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(2, $exitCode);
        self::assertStringContainsString($message, $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/comparison');
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

    public function test_runs_without_any_paired_input_exit_with_two(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            (new RunFixture('run-20261107T130000Z', scenarioId: 'visible-sha256'))->succeeded(self::INPUT, ['pdfsig' => 'invalid', 'qpdf' => 'valid']),
        );

        [$exitCode, $output, $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(2, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('have no available input in common under the same scenario and with the same content', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/comparison');
    }

    public function test_an_interrupted_write_leaves_no_comparison_directory_and_can_be_retried(): void
    {
        [$reference, $candidate] = $this->writeRuns(
            (new RunFixture('run-20261107T120000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            (new RunFixture('run-20261107T130000Z'))->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid']),
        );

        [$exitCode] = $this->runCommand(['prlimit', '--fsize=1000', PHP_BINARY, __DIR__.'/../../bin/compare-runs', $reference, $candidate, $this->directory.'/comparison']);

        self::assertNotSame(0, $exitCode);
        self::assertFileDoesNotExist($this->directory.'/comparison');

        [$exitCode, , $errorOutput] = $this->compareRuns($reference, $candidate, $this->directory.'/comparison');

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertFileExists($this->directory.'/comparison/comparison.md');
        $this->readComparison($this->directory.'/comparison');
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
