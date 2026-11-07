<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Run\RunReport;

final class RunReportTest extends TestCase
{
    private const string HYBRID_REFUSAL = 'Settings do not permit validation of signatures in hybrid-reference files.';

    private const string STARTXREF_ERROR = 'pdf-signer exited with code 1: Signing failed: Could not sign PDF using native v1 engine. Root cause: startxref not found';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/run-report-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    private static function fixture(): RunFixture
    {
        $fixture = new RunFixture('run-20261107T120000Z', ['pyhanko' => '0.38.0', 'pdfsig' => '22.12.0', 'qpdf' => '11.3.0']);
        $refusal = static fn (string $field): array => ["pyHanko refused to judge the signature(s) {$field} under its strict syntax policy: ".self::HYBRID_REFUSAL, ['signatures' => [['field' => $field, 'status' => 'INVALID', 'error' => self::HYBRID_REFUSAL]]]];

        return $fixture
            ->succeeded('qpdf:signed.pdf', ['pyhanko' => 'valid', 'pdfsig' => 'valid', 'qpdf' => 'valid'])->stratify('qpdf:signed.pdf', ['classic', 'incremental'])
            ->succeeded('qpdf:hybrid-a.pdf', ['pyhanko' => 'inconclusive', 'pdfsig' => 'valid', 'qpdf' => 'valid'])->stratify('qpdf:hybrid-a.pdf', ['hybrid', 'objstm'])
            ->evidence('qpdf:hybrid-a.pdf', 'pyhanko', ...$refusal('SignatureTTHOpu3U'))
            ->succeeded('qpdf:hybrid-b.pdf', ['pyhanko' => 'inconclusive', 'pdfsig' => 'valid', 'qpdf' => 'valid'])->stratify('qpdf:hybrid-b.pdf', ['hybrid'])
            ->evidence('qpdf:hybrid-b.pdf', 'pyhanko', ...$refusal('SignaturePYBP9ToU'))
            ->failed('qpdf:broken.pdf', message: self::STARTXREF_ERROR."\nstack trace")->stratify('qpdf:broken.pdf', ['none', 'malformed'])
            ->failed('qpdf:broken-too.pdf', message: self::STARTXREF_ERROR)->stratify('qpdf:broken-too.pdf', ['stream', 'malformed'])
            ->failed('qpdf:not-a-pdf.pdf', capability: 'expected_rejection', message: 'pdf-signer exited with code 1: PDF version not found')->stratify('qpdf:not-a-pdf.pdf', ['none', 'malformed'])
            ->unavailable('verapdf:gone.pdf')->stratify('verapdf:gone.pdf', ['classic'])
            ->unsupported('verapdf:encrypted.pdf')->stratify('verapdf:encrypted.pdf', ['stream', 'encrypted'])
            ->succeeded('verapdf:signs-anyway.pdf', ['pyhanko' => 'valid', 'pdfsig' => 'valid', 'qpdf' => 'valid'], capability: 'expected_rejection')->stratify('verapdf:signs-anyway.pdf', ['classic', 'signed']);
    }

    private function report(?RunFixture $fixture = null): string
    {
        return RunReport::fromDirectory(($fixture ?? self::fixture())->write($this->directory));
    }

    public function test_coverage_counts_every_attempt_of_each_stratum_including_the_ones_not_signed(): void
    {
        $report = $this->report();

        self::assertStringContainsString("### Scenario `basic-invisible-sha256`\n\n| Stratum | Attempts | Unavailable (`not_evaluated`) | `unsupported` | Rejected as expected | Not signed | Signed |\n", $report);
        self::assertStringContainsString(implode("\n", [
            '| `classic` | 3 | 1 | 0 | 0 | 0 | 2 |',
            '| `stream` | 2 | 0 | 1 | 0 | 1 | 0 |',
            '| `hybrid` | 2 | 0 | 0 | 0 | 0 | 2 |',
            '| `none` | 2 | 0 | 0 | 1 | 1 | 0 |',
            '| `objstm` | 1 | 0 | 0 | 0 | 0 | 1 |',
            '| `encrypted` | 1 | 0 | 1 | 0 | 0 | 0 |',
            '| `incremental` | 1 | 0 | 0 | 0 | 0 | 1 |',
            '| `signed` | 1 | 0 | 0 | 0 | 0 | 1 |',
            '| `malformed` | 3 | 0 | 0 | 1 | 2 | 0 |',
            '| All inputs | 9 | 1 | 1 | 1 | 2 | 4 |',
        ])."\n", $report);
        self::assertStringContainsString('Strata overlap: an input usually belongs to several strata, so the rows of a table do not add up to its last row.', $report);
    }

    public function test_stratum_without_inputs_is_reported_with_zero_attempts(): void
    {
        $fixture = (new RunFixture('run-20261107T120000Z'))
            ->succeeded('qpdf:signed.pdf', ['pdfsig' => 'valid', 'qpdf' => 'valid'])->stratify('qpdf:signed.pdf', ['classic']);

        self::assertStringContainsString("| `hybrid` | 0 | 0 | 0 | 0 | 0 | 0 |\n", $this->report($fixture));
    }

    public function test_each_analyzer_has_its_own_verdicts_by_stratum_with_not_evaluated_in_the_denominator(): void
    {
        $report = $this->report();

        self::assertStringContainsString(implode("\n", [
            '#### `pyhanko` (`cms_signature`)',
            '',
            '| Stratum | Attempts | `valid` | `invalid` | `inconclusive` | `not_evaluated` | `not_applicable` |',
        ]), $report);
        self::assertStringContainsString("| `hybrid` | 2 | 0 | 0 | 2 | 0 | 0 |\n| `none` | 2 | 0 | 0 | 0 | 2 | 0 |\n", $report);
        self::assertStringContainsString("| `hybrid` | 2 | 2 | 0 | 0 | 0 | 0 |\n| `none` | 2 | 0 | 0 | 0 | 2 | 0 |\n", $report);
        self::assertStringContainsString("| All inputs | 9 | 2 | 0 | 2 | 5 | 0 |\n", $report);
        self::assertStringContainsString("| All inputs | 9 | 4 | 0 | 0 | 5 | 0 |\n", $report);
        self::assertStringContainsString('#### `qpdf` (`structure`)', $report);
    }

    public function test_validator_disagreements_are_listed_without_a_combined_verdict(): void
    {
        $report = $this->report();

        self::assertStringContainsString('no verdict is chosen by vote. 2 disagreements over 9 attempts.', $report);
        self::assertStringContainsString("| `qpdf:hybrid-a.pdf` | `cms_signature` | `pyhanko` `inconclusive`, `pdfsig` `valid` |\n", $report);
        self::assertStringContainsString("| `qpdf:hybrid-b.pdf` | `cms_signature` | `pyhanko` `inconclusive`, `pdfsig` `valid` |\n", $report);
        self::assertStringNotContainsString('`qpdf:signed.pdf` | `cms_signature`', $report);
    }

    public function test_run_without_disagreements_says_so_with_its_denominator(): void
    {
        $fixture = (new RunFixture('run-20261107T120000Z'))
            ->succeeded('qpdf:signed.pdf', ['pdfsig' => 'valid', 'qpdf' => 'invalid'])->stratify('qpdf:signed.pdf', ['classic']);

        self::assertStringContainsString('No two analyzers of the same property gave different verdicts on the same attempt, out of 1 attempts.', $this->report($fixture));
    }

    public function test_failures_are_grouped_by_cause_with_signature_field_names_replaced(): void
    {
        $report = $this->report();

        self::assertStringContainsString(implode("\n", [
            '| Attempts | Unavailable (`not_evaluated`) | `unsupported` | Rejected as expected | Not signed | Signed | With a failure |',
            '| -------: | ----------------------------: | ------------: | -------------------: | ---------: | -----: | -------------: |',
            '| 9 | 1 | 1 | 1 | 2 | 4 | 6 |',
        ]), $report);
        self::assertStringContainsString(implode("\n", [
            '| Stage | Analyzer | Result | Cause | Attempts (of 9) | Inputs |',
            '| ----- | -------- | ------ | ----- | --------------: | ------ |',
            '| Execution | none | `failed` | '.self::STARTXREF_ERROR.' | 2 | `qpdf:broken.pdf`, `qpdf:broken-too.pdf` |',
            '| Execution | none | `not_run` | The scenario is not supported | 1 | `verapdf:encrypted.pdf` |',
            '| Execution | none | `succeeded` | Expected to be rejected, but signed | 1 | `verapdf:signs-anyway.pdf` |',
            '| Output | `pyhanko` | `inconclusive` | pyHanko refused to judge the signature(s) <signature> under its strict syntax policy: '.self::HYBRID_REFUSAL.' | 2 | `qpdf:hybrid-a.pdf`, `qpdf:hybrid-b.pdf` |',
        ])."\n", $report);
        self::assertStringNotContainsString('verapdf:gone.pdf` |', explode('### By cause', $report)[1]);
        self::assertStringNotContainsString('qpdf:not-a-pdf.pdf', explode('## Failures', $report)[1]);
    }

    public function test_field_name_is_replaced_only_as_a_whole_name(): void
    {
        $fixture = (new RunFixture('run-20261107T120000Z', ['pyhanko' => '0.38.0']))
            ->succeeded('qpdf:signed.pdf', ['pyhanko' => 'invalid'])->stratify('qpdf:signed.pdf', ['signed'])
            ->evidence('qpdf:signed.pdf', 'pyhanko', 'pyHanko judged the signature(s) Signature1, Signature10 not intact or not cryptographically valid', ['signatures' => [['field' => 'Signature1'], ['field' => 'Signature10']]]);

        self::assertStringContainsString('| Output | `pyhanko` | `invalid` | pyHanko judged the signature(s) <signature>, <signature> not intact or not cryptographically valid | 1 | `qpdf:signed.pdf` |', $this->report($fixture));
    }

    public function test_signature_named_by_its_object_falls_in_the_same_cause_as_one_named_by_its_field(): void
    {
        $problem = 'its byte range extends past the end of the input, so the input does not hold every byte it covers';
        $fixture = (new RunFixture('run-20261107T120000Z', ['pdfsig' => '22.12.0']))
            ->succeeded('verapdf:by-object.pdf', ['pdfsig' => 'inconclusive'])->stratify('verapdf:by-object.pdf', ['signed'])
            ->evidence('verapdf:by-object.pdf', 'pdfsig', "5 0 R: {$problem}", ['signatures' => [['object' => '5 0 R', 'field' => null]]])
            ->succeeded('verapdf:by-field.pdf', ['pdfsig' => 'inconclusive'])->stratify('verapdf:by-field.pdf', ['signed'])
            ->evidence('verapdf:by-field.pdf', 'pdfsig', "Signature2: {$problem}", ['signatures' => [['object' => '21 0 R', 'field' => 'Signature2']]]);

        self::assertStringContainsString("| Output | `pdfsig` | `inconclusive` | <signature>: {$problem} | 2 | `verapdf:by-object.pdf`, `verapdf:by-field.pdf` |\n", $this->report($fixture));
    }

    public function test_work_paths_of_the_runner_do_not_split_an_execution_cause(): void
    {
        $message = static fn (string $attempt): string => 'jsignpdf exited with code 1: Error to sign PDF. ["INFO Opening input PDF file: \\/sandbox\\/run-a74df68773cf0d84\\/work\\/attempt-'.$attempt.'\\/'.md5($attempt).'.pdf","SEVERE Problem occured"]';
        $fixture = (new RunFixture('run-20261107T120000Z'))
            ->failed('qpdf:first.pdf', message: $message('a4e7270155e29487'))->stratify('qpdf:first.pdf', ['encrypted'])
            ->failed('qpdf:second.pdf', message: $message('5f8c7f33bad46ef6'))->stratify('qpdf:second.pdf', ['encrypted']);

        self::assertStringContainsString('| Execution | none | `failed` | jsignpdf exited with code 1: Error to sign PDF. ["INFO Opening input PDF file: <work path>","SEVERE Problem occured"] | 2 | `qpdf:first.pdf`, `qpdf:second.pdf` |', $this->report($fixture));
    }

    public function test_cause_is_shown_whole_so_long_causes_stay_distinguishable(): void
    {
        $preamble = 'jsignpdf exited with code 1: '.str_repeat('INFO Getting certificate chain | ', 10);
        $fixture = (new RunFixture('run-20261107T120000Z'))
            ->failed('qpdf:locked.pdf', message: $preamble.'BadPasswordException: Bad user password')->stratify('qpdf:locked.pdf', ['encrypted'])
            ->failed('qpdf:headless.pdf', message: $preamble.'InvalidPdfException: PDF header signature not found.')->stratify('qpdf:headless.pdf', ['malformed']);
        $escapedPreamble = str_replace('|', '\\|', $preamble);

        $report = $this->report($fixture);

        self::assertStringContainsString("| `failed` | {$escapedPreamble}BadPasswordException: Bad user password | 1 | `qpdf:locked.pdf` |", $report);
        self::assertStringContainsString("| `failed` | {$escapedPreamble}InvalidPdfException: PDF header signature not found. | 1 | `qpdf:headless.pdf` |", $report);
    }

    public function test_failures_are_grouped_by_input_source(): void
    {
        $report = $this->report();

        self::assertStringContainsString(implode("\n", [
            '| Source | Attempts | Unavailable (`not_evaluated`) | `unsupported` | Rejected as expected | Not signed | Signed | Execution failures | With a failure |',
            '| ------ | -------: | ----------------------------: | ------------: | -------------------: | ---------: | -----: | -----------------: | -------------: |',
            '| `qpdf` | 6 | 0 | 0 | 1 | 2 | 3 | 2 | 4 |',
            '| `verapdf` | 3 | 1 | 1 | 0 | 0 | 1 | 2 | 2 |',
        ])."\n", $report);
        self::assertStringContainsString(implode("\n", [
            '| Source | Attempts | `pyhanko` | `pdfsig` | `qpdf` |',
            '| ------ | -------: | --------: | -------: | -----: |',
            '| `qpdf` | 6 | 2 | 0 | 0 |',
            '| `verapdf` | 3 | 0 | 0 | 0 |',
        ])."\n", $report);
    }

    public function test_failure_enriched_sample_is_not_reported_as_a_compatibility_rate(): void
    {
        self::assertStringContainsString('**This sample is failure-enriched, so no count or share in this report is a compatibility rate.**', $this->report());
    }

    public function test_representative_sample_carries_no_failure_enriched_warning(): void
    {
        $report = $this->report(self::fixture()->selection('representative'));

        self::assertStringNotContainsString('failure-enriched', $report);
        self::assertStringContainsString('The source configuration declares this sample representative of the documents found in practice.', $report);
    }

    public function test_regression_cases_are_left_out_of_the_counts(): void
    {
        $report = $this->report(self::fixture()->regression('hybrid-xref', 'present', ['pyhanko' => 'invalid', 'pdfsig' => 'valid', 'qpdf' => 'valid']));

        self::assertStringContainsString('The regression cases replayed by the run are a failure-enriched selection of their own, so they are not counted here.', $report);
        self::assertStringNotContainsString('regression:hybrid-xref.pdf', $report);
        self::assertStringContainsString("| All inputs | 9 | 1 | 1 | 1 | 2 | 4 |\n", $report);
    }

    public function test_run_without_a_copy_of_its_source_configuration_is_refused(): void
    {
        $directory = (new RunFixture('run-20261107T120000Z'))->succeeded('qpdf:signed.pdf', ['pdfsig' => 'valid', 'qpdf' => 'valid'])->write($this->directory);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no inputs.json, the copy of the source configuration used by the run');

        RunReport::fromDirectory($directory);
    }

    public function test_source_configuration_other_than_the_one_recorded_is_refused(): void
    {
        $directory = self::fixture()->write($this->directory);
        file_put_contents($directory.'/inputs.json', ' ', FILE_APPEND);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not the source configuration recorded in the run.json');

        RunReport::fromDirectory($directory);
    }

    public function test_declared_input_without_an_attempt_is_refused(): void
    {
        $fixture = self::fixture()->stratify('qpdf:never-tried.pdf', ['classic']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The run has no attempt on these declared inputs: qpdf:never-tried.pdf');

        $this->report($fixture);
    }

    public function test_attempt_on_an_undeclared_input_is_refused(): void
    {
        $fixture = self::fixture()->succeeded('qpdf:extra.pdf', ['pyhanko' => 'valid', 'pdfsig' => 'valid', 'qpdf' => 'valid']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The run has attempts on inputs its source configuration does not declare: qpdf:extra.pdf');

        $this->report($fixture);
    }

    public function test_command_writes_the_report_into_the_run_directory_and_prints_it(): void
    {
        $directory = self::fixture()->write($this->directory);

        [$exitCode, $output, $errorOutput] = self::runCommand([$directory]);

        self::assertSame(0, $exitCode, $errorOutput);
        self::assertSame(RunReport::fromDirectory($directory), $output);
        self::assertStringEqualsFile($directory.'/report.md', $output);
    }

    public function test_command_refuses_a_run_it_cannot_report_without_writing_anything(): void
    {
        $directory = (new RunFixture('run-20261107T120000Z'))->succeeded('qpdf:signed.pdf', ['pdfsig' => 'valid', 'qpdf' => 'valid'])->write($this->directory);

        [$exitCode, $output, $errorOutput] = self::runCommand([$directory]);

        self::assertSame(2, $exitCode);
        self::assertSame('', $output);
        self::assertStringContainsString('has no inputs.json', $errorOutput);
        self::assertFileDoesNotExist($directory.'/report.md');
    }

    public function test_command_requires_exactly_one_run_directory(): void
    {
        [$exitCode, , $errorOutput] = self::runCommand([]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Usage: report <run-directory>', $errorOutput);
    }

    /**
     * @param  list<string>  $arguments
     * @return array{int, string, string}
     */
    private static function runCommand(array $arguments): array
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/../../bin/report', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errorOutput = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errorOutput];
    }
}
