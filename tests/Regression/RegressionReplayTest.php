<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Regression;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Regression\HybridXrefCondition;
use SignerPHP\PdfSigningQualification\Regression\RegressionCase;
use SignerPHP\PdfSigningQualification\Regression\RegressionConditions;
use SignerPHP\PdfSigningQualification\Regression\RegressionReplay;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\NetworkIsolation;
use SignerPHP\PdfSigningQualification\Signing\ResourceLimits;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Signing\TestCredential;
use SignerPHP\PdfSigningQualification\Tests\Fixtures\Synthetic\HybridXrefPdf;
use SignerPHP\PdfSigningQualification\Tests\Signing\CommandImplementation;
use SignerPHP\PdfSigningQualification\Tests\Validation\FakeCommandRunner;
use SignerPHP\PdfSigningQualification\Tests\Validation\SyntheticSignedPdf;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\QpdfCheckValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;

final class RegressionReplayTest extends TestCase
{
    private const string RUN_ID = 'run-20261107T120000Z';

    private const string SCENARIO_PATH = __DIR__.'/../../config/scenarios/basic-invisible-sha256.json';

    private const string HYBRID_XREF_SHA256 = 'd1ecc4e4aa7b5fd6ef5ed4eed7ac32c81ce426b44169de33d32c8366e56112bd';

    private string $directory;

    private string $workDirectory;

    private TestCredential $credential;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regression-replay-'.bin2hex(random_bytes(6));
        $this->workDirectory = '/sandbox/regression-replay-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/credential', 0777, true);
        mkdir($this->workDirectory);
        $this->credential = TestCredential::generate($this->directory.'/credential');
    }

    protected function tearDown(): void
    {
        $this->credential->remove();
        exec('rm -rf '.escapeshellarg($this->directory).' '.escapeshellarg($this->workDirectory));
    }

    private function replay(): RegressionReplay
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-07T12:00:00Z');
        $validatorRunner = new FakeCommandRunner(new CommandResult(0, "qpdf version 11.3.0\n"), new CommandResult(0, ''));

        return new RegressionReplay(
            new AttemptRunner(new ProcessRunner, new NetworkIsolation, $this->credential, $this->workDirectory, $this->directory.'/outputs', new ResourceLimits, $clock),
            new ValidationPanel([new QpdfCheckValidator($validatorRunner, $clock)]),
            new RegressionConditions(new ProcessRunner),
            $this->directory.'/regression-inputs',
            $clock,
        );
    }

    private static function scenario(): array
    {
        $scenario = json_decode((string) file_get_contents(self::SCENARIO_PATH), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($scenario);

        return $scenario;
    }

    private static function copyingImplementation(bool $supported = true): SigningImplementation
    {
        return new CommandImplementation(static fn (): array => ['cp', 'input.pdf', 'output.pdf'], $supported);
    }

    private static function hybridXrefCase(string $sha256 = self::HYBRID_XREF_SHA256, string $scenarioId = 'basic-invisible-sha256'): RegressionCase
    {
        return new RegressionCase(
            'hybrid-xref',
            $scenarioId,
            ['kind' => 'synthetic', 'generator' => 'tests/Fixtures/Synthetic/HybridXrefPdf.php', 'sha256' => $sha256, 'byte_size' => 721],
            ['sha256' => str_repeat('a', 64), 'byte_size' => 600],
        );
    }

    private static function upstreamCase(string $sourceId): RegressionCase
    {
        return new RegressionCase('hybrid-xref', 'basic-invisible-sha256', ['kind' => 'upstream', 'source_id' => $sourceId], ['sha256' => str_repeat('a', 64), 'byte_size' => 600]);
    }

    public function test_synthetic_case_is_signed_validated_and_its_condition_checked(): void
    {
        $regressions = $this->replay()->replay(self::RUN_ID, self::scenario(), self::copyingImplementation(), [self::hybridXrefCase()], []);

        self::assertSame(self::RUN_ID, $regressions['run_id']);
        self::assertCount(1, $regressions['attempts']);
        $attempt = $regressions['attempts'][0];
        self::assertSame(self::RUN_ID.'/basic-invisible-sha256/command-signer/regression:hybrid-xref.pdf', $attempt['id']);
        self::assertSame(['status' => 'available', 'source_id' => 'regression:hybrid-xref.pdf', 'sha256' => self::HYBRID_XREF_SHA256, 'byte_size' => 721], $attempt['input']);
        self::assertSame('succeeded', $attempt['execution']['outcome']);
        self::assertSame([$attempt['id']], array_column($regressions['observations'], 'attempt_id'));
        self::assertSame([[
            'case_id' => 'hybrid-xref',
            'scenario_id' => 'basic-invisible-sha256',
            'definition' => ['sha256' => str_repeat('a', 64), 'byte_size' => 600],
            'attempt_id' => $attempt['id'],
            'condition' => ['status' => 'present', 'evidence' => 'The final trailer of the output has /XRefStm 445, inside the 721 bytes of the input'],
        ]], $regressions['cases']);
    }

    public function test_generator_that_no_longer_writes_the_declared_input_is_recorded_as_unavailable(): void
    {
        $regressions = $this->replay()->replay(self::RUN_ID, self::scenario(), self::copyingImplementation(), [self::hybridXrefCase(str_repeat('0', 64))], []);

        $attempt = $regressions['attempts'][0];
        self::assertSame('unavailable', $attempt['input']['status']);
        self::assertSame('hash_mismatch', $attempt['input']['reason']);
        self::assertSame(self::HYBRID_XREF_SHA256, $attempt['input']['observed_sha256']);
        self::assertSame('not_run', $attempt['execution']['outcome']);
        self::assertSame(['not_evaluated'], array_column($regressions['observations'], 'verdict'));
        self::assertSame([null], array_column($regressions['observations'], 'input_artifact'));
        self::assertSame('not_evaluated', $regressions['cases'][0]['condition']['status']);
        self::assertStringStartsWith('The input is unavailable (hash_mismatch): tests/Fixtures/Synthetic/HybridXrefPdf.php wrote 721 bytes', $regressions['cases'][0]['condition']['evidence']);
    }

    public function test_case_of_another_scenario_is_reported_without_an_attempt(): void
    {
        $regressions = $this->replay()->replay(self::RUN_ID, self::scenario(), self::copyingImplementation(), [self::hybridXrefCase(scenarioId: 'other-scenario')], []);

        self::assertSame([], $regressions['attempts']);
        self::assertNull($regressions['cases'][0]['attempt_id']);
        self::assertSame(['status' => 'not_evaluated', 'evidence' => 'The case runs under scenario other-scenario, and this run is of scenario basic-invisible-sha256'], $regressions['cases'][0]['condition']);
    }

    public function test_unsupported_scenario_is_reported_as_not_evaluated(): void
    {
        $regressions = $this->replay()->replay(self::RUN_ID, self::scenario(), self::copyingImplementation(supported: false), [self::hybridXrefCase()], []);

        self::assertSame('unsupported', $regressions['attempts'][0]['capability']);
        self::assertSame(['status' => 'not_evaluated', 'evidence' => 'command-signer does not support the scenario basic-invisible-sha256, so the input was not signed'], $regressions['cases'][0]['condition']);
    }

    public function test_failed_attempt_has_no_output_to_check(): void
    {
        $failing = new CommandImplementation(static fn (): array => ['false']);

        $regressions = $this->replay()->replay(self::RUN_ID, self::scenario(), $failing, [self::hybridXrefCase()], []);

        self::assertSame('failed', $regressions['attempts'][0]['execution']['outcome']);
        self::assertSame(['status' => 'not_evaluated', 'evidence' => 'The attempt ended as failed, so there is no output to check'], $regressions['cases'][0]['condition']);
    }

    public function test_upstream_case_uses_the_attempt_of_the_run_on_its_input(): void
    {
        $inputPath = HybridXrefPdf::create($this->directory);
        $attempt = ['id' => self::RUN_ID.'/basic-invisible-sha256/command-signer/qpdf:hybrid-xref.pdf', 'scenario_id' => 'basic-invisible-sha256', 'implementation' => ['id' => 'command-signer'], 'capability' => 'supported', 'input' => ['status' => 'available'], 'execution' => ['outcome' => 'succeeded']];

        $regressions = $this->replay()->replay(self::RUN_ID, self::scenario(), self::copyingImplementation(), [self::upstreamCase('qpdf:hybrid-xref.pdf')], [
            'qpdf:hybrid-xref.pdf' => ['attempt' => $attempt, 'input_path' => $inputPath, 'output_path' => $inputPath],
        ]);

        self::assertSame([], $regressions['attempts']);
        self::assertSame($attempt['id'], $regressions['cases'][0]['attempt_id']);
        self::assertSame('present', $regressions['cases'][0]['condition']['status']);
    }

    public function test_upstream_case_on_an_undeclared_input_is_reported_without_an_attempt(): void
    {
        $regressions = $this->replay()->replay(self::RUN_ID, self::scenario(), self::copyingImplementation(), [self::upstreamCase('qpdf:hybrid-xref.pdf')], []);

        self::assertNull($regressions['cases'][0]['attempt_id']);
        self::assertSame(['status' => 'not_evaluated', 'evidence' => 'The input qpdf:hybrid-xref.pdf is not declared in the source configuration of this run'], $regressions['cases'][0]['condition']);
    }

    public function test_synthetic_input_that_is_also_a_declared_input_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('which is also a declared input of the run');

        $this->replay()->replay(self::RUN_ID, self::scenario(), self::copyingImplementation(), [self::hybridXrefCase()], [
            'regression:hybrid-xref.pdf' => ['attempt' => [], 'input_path' => null, 'output_path' => null],
        ]);
    }

    public function test_case_without_a_condition_check_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The regression case unknown-case has no condition check');

        (new RegressionConditions(new ProcessRunner))->for('unknown-case');
    }

    public function test_hybrid_xref_condition_is_absent_from_an_output_without_the_previous_xref_stream(): void
    {
        $inputPath = HybridXrefPdf::create($this->directory);
        $outputPath = SyntheticSignedPdf::create($this->directory);

        self::assertSame(['status' => 'absent', 'evidence' => 'The final trailer of the output has no /XRefStm'], (new HybridXrefCondition(new ProcessRunner))->evaluate($inputPath, $outputPath));
    }

    public function test_hybrid_xref_condition_is_absent_when_the_xref_stream_belongs_to_the_new_revision(): void
    {
        $inputPath = HybridXrefPdf::create($this->directory);
        $runner = new FakeCommandRunner(new CommandResult(0, ''), new CommandResult(0, "<< /Root 1 0 R /Size 9 /XRefStm 900 >>\n"));

        self::assertSame(['status' => 'absent', 'evidence' => 'The final trailer of the output has /XRefStm 900, after the 721 bytes of the input'], (new HybridXrefCondition($runner))->evaluate($inputPath, $inputPath));
    }

    public function test_hybrid_xref_condition_is_undetermined_when_qpdf_cannot_read_the_output(): void
    {
        $inputPath = HybridXrefPdf::create($this->directory);
        $outputPath = $this->directory.'/not-a-pdf.pdf';
        file_put_contents($outputPath, 'not a PDF');

        $condition = (new HybridXrefCondition(new ProcessRunner))->evaluate($inputPath, $outputPath);

        self::assertSame('undetermined', $condition['status']);
        self::assertStringStartsWith('qpdf could not show the final trailer of the output: it exited with code 2', $condition['evidence']);
    }
}
