<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Run\DifferentialComparison;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class CompareImplementationsCommandTest extends TestCase
{
    private const string INPUT = 'qpdf:qpdf/qtest/qpdf/good17.pdf';

    private const array ANALYZERS = ['pdfsig' => '22.12.0', 'qpdf' => '11.3.0'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/compare-implementations-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @return array{int, string, string}
     */
    private function compareImplementations(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../bin/compare-implementations', ...$arguments],
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

    /**
     * @param  array<string, string>  $firstVerdicts
     * @return list<string>
     */
    private function writeRuns(array $firstVerdicts = ['pdfsig' => 'valid', 'qpdf' => 'valid']): array
    {
        $directories = [];
        foreach ([['pdf-signer', '12', $firstVerdicts], ['jsignpdf', '13', ['pdfsig' => 'valid', 'qpdf' => 'valid']]] as [$implementationId, $hour, $verdicts]) {
            $revision = $implementationId === 'jsignpdf' ? ['kind' => 'version', 'value' => '3.2.1'] : ['kind' => 'commit', 'value' => str_repeat('a', 40)];
            foreach (['00', '30'] as $minute) {
                $run = (new RunFixture("run-20261107T{$hour}{$minute}00Z", self::ANALYZERS))
                    ->implementation($implementationId, $revision)
                    ->succeeded(self::INPUT, $verdicts)
                    ->baselines(self::INPUT, ['pdfsig' => 'not_applicable', 'qpdf' => 'valid'])
                    ->stratify(self::INPUT, ['hybrid'])
                    ->selection('stratified');
                $directories[] = $run->write($this->directory.'/'.$run->run['id']);
            }
        }

        return $directories;
    }

    public function test_it_writes_the_classified_comparison_and_prints_its_markdown(): void
    {
        $runs = $this->writeRuns(['pdfsig' => 'valid', 'qpdf' => 'invalid']);

        [$exitCode, $output, $errorOutput] = $this->compareImplementations(...[...$runs, $this->directory.'/differential']);

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertSame((string) file_get_contents($this->directory.'/differential/differential.md'), $output);
        $comparison = json_decode((string) file_get_contents($this->directory.'/differential/differential.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($comparison);
        self::assertSame([], (new SchemaValidator)->errors($comparison, DifferentialComparison::SCHEMA));
        self::assertSame('stratified', $comparison['selection']);
        self::assertSame(1, $comparison['totals']['items']['reproducible_implementation_defect']);
        self::assertStringContainsString("### `pdf-signer`\n\n| Input | Item | `pdf-signer` | `jsignpdf` |", $output);
        self::assertStringContainsString('| `'.self::INPUT.'` | verdict of `qpdf` | `invalid` | `valid` |', $output);
        self::assertStringContainsString('`run-20261107T120000Z/basic-invisible-sha256/pdf-signer/'.self::INPUT.'/qpdf`', $output);
        self::assertStringContainsString('**This sample is stratified', $output);
        self::assertStringContainsString('Agreement between the implementations does not establish conformance', $output);
    }

    public function test_runs_of_one_implementation_only_are_refused_without_writing_anything(): void
    {
        $runs = $this->writeRuns();

        [$exitCode, $output, $errorOutput] = $this->compareImplementations($runs[0], $runs[1], $runs[0], $runs[1], $this->directory.'/differential');

        self::assertSame(2, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('more than once', $errorOutput);
        self::assertFileDoesNotExist($this->directory.'/differential');
    }

    public function test_a_baseline_of_another_run_is_refused(): void
    {
        $runs = $this->writeRuns();
        $baselines = json_decode((string) file_get_contents($runs[3].'/baselines.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baselines);
        $baselines[0]['run_id'] = 'run-20261107T000000Z';
        file_put_contents($runs[3].'/baselines.json', json_encode($baselines, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [$exitCode, , $errorOutput] = $this->compareImplementations(...[...$runs, $this->directory.'/differential']);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('is not for an input of the run run-20261107T133000Z', $errorOutput);
    }

    public function test_a_baseline_of_other_content_than_the_attempted_input_is_refused(): void
    {
        $runs = $this->writeRuns();
        $baselines = json_decode((string) file_get_contents($runs[3].'/baselines.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baselines);
        $baselines[0]['input_artifact']['sha256'] = str_repeat('f', 64);
        file_put_contents($runs[3].'/baselines.json', json_encode($baselines, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [$exitCode, , $errorOutput] = $this->compareImplementations(...[...$runs, $this->directory.'/differential']);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('is not of the input the run attempted', $errorOutput);
    }

    public function test_an_unavailable_image_reason_cannot_break_the_runs_table(): void
    {
        $runs = $this->writeRuns();
        $run = json_decode((string) file_get_contents($runs[0].'/run.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($run);
        $run['environment']['container_image'] = ['status' => 'unavailable', 'reason' => "no id | given\nby the host"];
        file_put_contents($runs[0].'/run.json', json_encode($run, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [$exitCode, $output, $errorOutput] = $this->compareImplementations(...[...$runs, $this->directory.'/differential']);

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertStringContainsString('| `pdf-signer` | `run-20261107T120000Z` | unavailable: no id \\| given by the host | not recorded |', $output);
    }

    public function test_a_wrong_number_of_arguments_prints_the_usage(): void
    {
        [$exitCode, , $errorOutput] = $this->compareImplementations($this->directory);

        self::assertSame(2, $exitCode);
        self::assertStringStartsWith('Usage: compare-implementations', $errorOutput);
    }
}
