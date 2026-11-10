<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Performance\PerformanceAnalysis;
use SignerPHP\PdfSigningQualification\Performance\Protocol;
use SignerPHP\PdfSigningQualification\Performance\StudyRecords;
use SignerPHP\PdfSigningQualification\Tests\Run\RunFixture;

final class PerformanceAnalysisTest extends TestCase
{
    private const string SMALL = 'qpdf:qpdf/qtest/qpdf/small.pdf';

    private const string LARGE = 'qpdf:qpdf/qtest/qpdf/large.pdf';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/performance-analysis-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->directory));
    }

    /**
     * @param  array{label: string, implementation_id: string, role: string}  $planned
     */
    private static function signsBoth(RunFixture $run, array $planned, float $scale = 1.0): void
    {
        $jsignpdf = $planned['implementation_id'] === 'jsignpdf';
        $warmUp = $planned['role'] === 'warm_up' ? 10.0 : 1.0;
        $repetition = ((int) substr($planned['label'], 0, 2)) % 2 === 0 ? 1.1 : 1.0;
        $run->succeeded(self::SMALL, StudyFixture::VALID)->measured(self::SMALL, ($jsignpdf ? 1.0 : 0.2) * $repetition * $warmUp * $scale, $jsignpdf ? 1.5 : 0.15, 0.05, $jsignpdf ? 130_000_000 : 40_000_000);
        $run->succeeded(self::LARGE, StudyFixture::VALID)->measured(self::LARGE, ($jsignpdf ? 2.0 : 1.0) * $repetition * $warmUp * $scale, $jsignpdf ? 2.5 : 0.9, 0.1, $jsignpdf ? 150_000_000 : 200_000_000);
    }

    private function study(string|int $session, ?\Closure $attempts = null, array $protocolChanges = [], ?\Closure $runChanges = null): StudyRecords
    {
        $attempts ??= static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned);

        return StudyRecords::fromDirectory(StudyFixture::write($this->directory, $session, $attempts, $protocolChanges, $runChanges));
    }

    public function test_paired_medians_ratios_and_differences_are_taken_over_document_medians_of_the_measured_runs(): void
    {
        $analysis = PerformanceAnalysis::analyze([$this->study(1)]);
        $wallTime = $analysis['sessions'][0]['metrics']['wall_time_seconds'];

        self::assertSame(['pdf-signer' => 2, 'jsignpdf' => 2], $analysis['sessions'][0]['measured_runs']);
        self::assertEqualsWithDelta(0.63, $wallTime['implementations']['pdf-signer']['median'], 1e-9);
        self::assertEqualsWithDelta(1.575, $wallTime['implementations']['jsignpdf']['median'], 1e-9);
        self::assertEqualsWithDelta(0.35, $wallTime['ratio']['median'], 1e-9);
        self::assertSame(2, $wallTime['ratio']['documents']);
        self::assertEqualsWithDelta(-0.945, $wallTime['difference']['median'], 1e-9);
        self::assertSame(2, $wallTime['difference']['first_lower']);
        self::assertLessThanOrEqual($wallTime['ratio']['median'], $wallTime['ratio']['interval'][0] - 1e-12 + 1e-9);
        self::assertGreaterThanOrEqual($wallTime['ratio']['median'] - 1e-9, $wallTime['ratio']['interval'][1]);
        self::assertSame([0.1, 0.25, 0.5, 0.75, 0.9], array_column($wallTime['ratio']['quantiles'], 'probability'));

        $memory = $analysis['sessions'][0]['metrics']['peak_memory_bytes'];
        self::assertSame(1, $memory['difference']['first_lower']);
        self::assertEqualsWithDelta((40 / 130 + 200 / 150) / 2, $memory['ratio']['median'], 1e-9);
        self::assertEqualsWithDelta(0.6, $analysis['sessions'][0]['metrics']['cpu_seconds']['implementations']['pdf-signer']['median'], 1e-9);
    }

    public function test_warm_up_runs_stay_out_of_the_distributions_and_are_listed_in_execution_order(): void
    {
        $session = PerformanceAnalysis::analyze([$this->study(1)])['sessions'][0];

        self::assertSame(['warm_up', 'warm_up', 'measured', 'measured', 'measured', 'measured'], array_column($session['runs'], 'role'));
        $warmUp = array_values(array_filter($session['runs'], static fn (array $run): bool => $run['role'] === 'warm_up' && $run['implementation_id'] === 'pdf-signer'))[0];
        self::assertGreaterThan(5.0, $warmUp['medians']['wall_time_seconds']);
        self::assertSame(['performance'], $warmUp['controls']['scaling_governors']);
        self::assertSame('4-7', $warmUp['controls']['cpuset']);
    }

    public function test_dispersion_is_the_coefficient_of_variation_of_each_document_over_the_measured_runs(): void
    {
        $dispersion = PerformanceAnalysis::analyze([$this->study(1)])['sessions'][0]['metrics']['wall_time_seconds']['dispersion'];

        self::assertEqualsWithDelta(sqrt(2) * 0.05 / 1.05, $dispersion['pdf-signer']['median'], 1e-9);
        self::assertEqualsWithDelta(0.0, PerformanceAnalysis::analyze([$this->study(2)])['sessions'][0]['metrics']['peak_memory_bytes']['dispersion']['jsignpdf']['maximum'], 1e-12);
    }

    public function test_only_inputs_valid_and_measured_in_every_measured_run_of_both_implementations_are_paired(): void
    {
        $attempts = static function (RunFixture $run, array $planned): void {
            self::signsBoth($run, $planned);
            $jsignpdf = $planned['implementation_id'] === 'jsignpdf';
            $second = str_starts_with($planned['label'], '04') || str_starts_with($planned['label'], '06');
            if ($jsignpdf && $second) {
                $run->failed('qpdf:fails-once.pdf');
            } else {
                $run->succeeded('qpdf:fails-once.pdf', StudyFixture::VALID)->measured('qpdf:fails-once.pdf', 1.0, 1.0, 0.1, 1_000);
            }
            $run->succeeded('qpdf:invalid.pdf', $jsignpdf ? StudyFixture::VALID : ['pdfsig' => 'invalid', 'qpdf' => 'valid'])->measured('qpdf:invalid.pdf', 1.0, 1.0, 0.1, 1_000);
            $run->succeeded('qpdf:inconclusive.pdf', ['pdfsig' => 'inconclusive', 'qpdf' => 'valid'])->measured('qpdf:inconclusive.pdf', 1.0, 1.0, 0.1, 1_000);
            $run->succeeded('qpdf:not-applicable.pdf', ['pdfsig' => 'valid', 'qpdf' => 'not_applicable'])->measured('qpdf:not-applicable.pdf', 1.0, 1.0, 0.1, 1_000);
            $run->failed('qpdf:rejected.pdf', capability: 'expected_rejection');
            $run->unsupported('qpdf:unsupported.pdf');
            $run->unavailable('qpdf:unavailable.pdf');
            $run->succeeded('qpdf:unmeasured.pdf', StudyFixture::VALID)->unmeasured('qpdf:unmeasured.pdf', 'The measurement process wrote no measurement');
        };
        $analysis = PerformanceAnalysis::analyze([$this->study(1, $attempts)]);

        self::assertSame([self::SMALL, self::LARGE, 'qpdf:not-applicable.pdf'], $analysis['eligibility']['eligible_inputs']);
        self::assertSame(['input_unavailable' => 1, 'not_attempted' => 2, 'execution_not_succeeded' => 1, 'output_not_valid' => 2, 'measurement_unavailable' => 1], $analysis['eligibility']['excluded']);
        self::assertSame(10, $analysis['eligibility']['inputs']);
        self::assertSame(3, $analysis['eligibility']['eligible']);
        self::assertSame(1, $analysis['coverage']['jsignpdf']['outcomes']['failed'] - $analysis['coverage']['pdf-signer']['outcomes']['failed']);
    }

    public function test_excluded_input_is_counted_under_the_earliest_condition_it_fails_whatever_the_order_of_the_runs(): void
    {
        $attempts = static function (RunFixture $run, array $planned): void {
            self::signsBoth($run, $planned);
            if ($planned['implementation_id'] === 'jsignpdf') {
                $run->failed('qpdf:both.pdf');
            } else {
                $run->succeeded('qpdf:both.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'valid'])->measured('qpdf:both.pdf', 1.0, 1.0, 0.1, 1_000);
            }
        };

        foreach ([Protocol::CALIBRATION, 1, 2] as $session) {
            $excluded = PerformanceAnalysis::analyze([StudyRecords::fromDirectory(StudyFixture::write($this->directory."/order-{$session}", $session, $attempts))])['eligibility']['excluded'];

            self::assertSame(1, $excluded['execution_not_succeeded'], "session {$session}");
            self::assertSame(0, $excluded['output_not_valid'], "session {$session}");
        }
    }

    public function test_failure_in_one_measured_run_excludes_the_input(): void
    {
        $attempts = static function (RunFixture $run, array $planned): void {
            self::signsBoth($run, $planned);
            if ($planned['implementation_id'] === 'jsignpdf' && $planned['role'] === 'measured' && ! isset($GLOBALS['failed-once'])) {
                $GLOBALS['failed-once'] = true;
                $run->failed('qpdf:fails-once.pdf', 'timed_out', null);
            } else {
                $run->succeeded('qpdf:fails-once.pdf', StudyFixture::VALID)->measured('qpdf:fails-once.pdf', 1.0, 1.0, 0.1, 1_000);
            }
        };
        unset($GLOBALS['failed-once']);
        $analysis = PerformanceAnalysis::analyze([$this->study(1, $attempts)]);
        unset($GLOBALS['failed-once']);

        self::assertSame(1, $analysis['eligibility']['excluded']['execution_not_succeeded']);
        self::assertSame(['valid_in_every_run' => 2, 'valid_in_some_runs' => 1, 'never_valid' => 0], $analysis['coverage']['jsignpdf']['inputs_by_validity']);
        self::assertSame(['succeeded' => 5, 'failed' => 0, 'timed_out' => 1, 'not_run' => 0], $analysis['coverage']['jsignpdf']['outcomes']);
        self::assertSame(6, $analysis['coverage']['jsignpdf']['attempts']);
        self::assertSame(5, $analysis['coverage']['jsignpdf']['valid_outputs']);
    }

    public function test_coverage_counts_every_measured_run_and_no_warm_up_run(): void
    {
        $coverage = PerformanceAnalysis::analyze([$this->study(1)])['coverage']['pdf-signer'];

        self::assertSame(2, $coverage['runs']);
        self::assertSame(4, $coverage['attempts']);
        self::assertSame(4, $coverage['valid_outputs']);
    }

    public function test_reference_is_summarized_per_implementation_over_the_measured_runs(): void
    {
        $references = PerformanceAnalysis::analyze([$this->study(1)])['sessions'][0]['references'];

        self::assertSame(4, $references['jsignpdf']['attempts']);
        self::assertSame(4, $references['jsignpdf']['valid_and_measured']);
        self::assertEqualsWithDelta(0.9, $references['jsignpdf']['metrics']['wall_time_seconds']['median'], 1e-12);
        self::assertEqualsWithDelta(0.11, $references['pdf-signer']['metrics']['cpu_seconds']['median'], 1e-12);
    }

    public function test_two_sessions_within_the_tolerance_repeat_the_result(): void
    {
        $later = static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned, 1.03);
        $analysis = PerformanceAnalysis::analyze([$this->study(1), $this->study(2, $later)]);

        self::assertCount(2, $analysis['sessions']);
        self::assertTrue($analysis['repeatability']['within_tolerance']);
        $wallTime = array_values(array_filter($analysis['repeatability']['items'], static fn (array $item): bool => $item['metric'] === 'wall_time_seconds' && $item['statistic'] === 'median of pdf-signer'))[0];
        self::assertEqualsWithDelta(0.03, $wallTime['relative_change'], 1e-9);
    }

    public function test_session_outside_the_tolerance_does_not_repeat_the_result(): void
    {
        $later = static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned, $planned['implementation_id'] === 'jsignpdf' ? 1.2 : 1.0);
        $repeatability = PerformanceAnalysis::analyze([$this->study(1), $this->study(2, $later)])['repeatability'];

        self::assertFalse($repeatability['within_tolerance']);
        $outside = array_values(array_filter($repeatability['items'], static fn (array $item): bool => ! $item['within_tolerance']));
        self::assertSame(['median of jsignpdf', 'median paired ratio'], array_column($outside, 'statistic'));
    }

    public function test_later_session_is_the_one_with_the_higher_number_whatever_the_order_of_the_arguments(): void
    {
        $later = static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned, $planned['implementation_id'] === 'jsignpdf' ? 1.11 : 1.0);
        $first = $this->study(1);
        $second = $this->study(2, $later);

        $inOrder = PerformanceAnalysis::analyze([$first, $second]);
        $reversed = PerformanceAnalysis::analyze([$second, $first]);

        self::assertFalse($inOrder['repeatability']['within_tolerance']);
        self::assertSame($inOrder['repeatability'], $reversed['repeatability']);
        self::assertSame([1, 2], array_column($reversed['sessions'], 'session'));
    }

    public function test_statistic_that_cannot_be_compared_is_listed_and_keeps_the_sessions_from_repeating(): void
    {
        $withoutMemory = static function (RunFixture $run, array $planned): void {
            $jsignpdf = $planned['implementation_id'] === 'jsignpdf';
            $run->succeeded(self::SMALL, StudyFixture::VALID)->measured(self::SMALL, $jsignpdf ? 1.0 : 0.2, 0.2, 0.05, $jsignpdf ? 0 : 40_000_000);
        };
        $repeatability = PerformanceAnalysis::analyze([$this->study(1, $withoutMemory), $this->study(2, $withoutMemory)])['repeatability'];

        self::assertFalse($repeatability['within_tolerance']);
        $memory = array_values(array_filter($repeatability['items'], static fn (array $item): bool => $item['metric'] === 'peak_memory_bytes'));
        self::assertSame(['median of pdf-signer', 'median of jsignpdf', 'median paired ratio'], array_column($memory, 'statistic'));
        self::assertSame([true, false, false], array_column($memory, 'within_tolerance'));
        self::assertNull($memory[1]['relative_change']);
        self::assertNull($memory[2]['earlier']);
    }

    public function test_single_session_has_no_repeatability(): void
    {
        self::assertNull(PerformanceAnalysis::analyze([$this->study(1)])['repeatability']);
    }

    public function test_calibration_session_is_analyzed_alone_with_every_run_measured(): void
    {
        $analysis = PerformanceAnalysis::analyze([$this->study(Protocol::CALIBRATION)]);

        self::assertSame('calibration', $analysis['sessions'][0]['session']);
        self::assertSame(['pdf-signer' => 2, 'jsignpdf' => 2], $analysis['sessions'][0]['measured_runs']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A calibration session is analyzed alone');
        PerformanceAnalysis::analyze([
            StudyRecords::fromDirectory(StudyFixture::write($this->directory.'/again', Protocol::CALIBRATION, static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned))),
            $this->study(1),
        ]);
    }

    public function test_sessions_of_different_protocols_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('follow different protocols');

        PerformanceAnalysis::analyze([$this->study(1), $this->study(2, protocolChanges: ['repeatability_tolerance' => 0.2])]);
    }

    public function test_same_session_twice_is_refused(): void
    {
        $study = $this->study(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The same session is given twice');

        PerformanceAnalysis::analyze([$study, $study]);
    }

    public function test_runs_on_another_image_are_refused(): void
    {
        $runChanges = static function (RunFixture $run, array $planned): void {
            if ($planned['label'] === '04-'.$planned['implementation_id']) {
                $run->run['environment']['container_image'] = ['status' => 'available', 'id' => 'sha256:'.str_repeat('f', 64)];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differ in their container image');

        PerformanceAnalysis::analyze([$this->study(1, runChanges: $runChanges)]);
    }

    public function test_runs_of_one_implementation_at_another_revision_are_refused(): void
    {
        $runChanges = static function (RunFixture $run, array $planned): void {
            if ($planned['label'] === '05-'.$planned['implementation_id'] && $planned['implementation_id'] === 'pdf-signer') {
                $run->implementationCommit(str_repeat('b', 40));
            }
            if ($planned['label'] === '06-'.$planned['implementation_id'] && $planned['implementation_id'] === 'pdf-signer') {
                $run->implementationCommit(str_repeat('b', 40));
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('are of pdf-signer at a different revision or runtime');

        PerformanceAnalysis::analyze([$this->study(1, runChanges: $runChanges)]);
    }

    public function test_session_without_every_planned_run_is_refused(): void
    {
        $directory = StudyFixture::write($this->directory, 1, static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned));
        exec('rm -rf '.escapeshellarg($directory).'/run-*-03-*');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('so the session is not complete');

        StudyRecords::fromDirectory($directory);
    }

    public function test_session_whose_plan_is_not_the_one_its_protocol_draws_is_refused(): void
    {
        $directory = StudyFixture::write($this->directory, 1, static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned));
        $study = json_decode((string) file_get_contents($directory.'/study.json'), true, flags: JSON_THROW_ON_ERROR);
        $study['runs'][0]['role'] = 'measured';
        file_put_contents($directory.'/study.json', json_encode($study, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not the plan its protocol draws for session 1');

        StudyRecords::fromDirectory($directory);
    }

    public static function brokenReferences(): iterable
    {
        yield 'fewer repetitions than the protocol' => [static function (array $reference): array {
            array_pop($reference['attempts']);
            array_pop($reference['measurements']);
            $reference['observations'] = array_slice($reference['observations'], 0, -2);

            return $reference;
        }, 'has 1 attempts, not the 2 repetitions of the protocol'];
        yield 'attempt recorded twice' => [static function (array $reference): array {
            $reference['attempts'][1] = $reference['attempts'][0];

            return $reference;
        }, 'is recorded more than once'];
        yield 'attempt without its measurement' => [static function (array $reference): array {
            array_pop($reference['measurements']);

            return $reference;
        }, 'does not have one measurement for each attempt that ran'];
        yield 'measured twice' => [static function (array $reference): array {
            $reference['measurements'][1] = $reference['measurements'][0];

            return $reference;
        }, 'does not have one measurement for each attempt that ran'];
        yield 'under another scenario than the run' => [static function (array $reference): array {
            foreach ($reference['attempts'] as $index => $attempt) {
                $reference['attempts'][$index]['scenario_id'] = 'other-scenario';
            }

            return $reference;
        }, 'not under the scenario basic-invisible-sha256 of the run'];
        yield 'judged by other analyzers than the run' => [static function (array $reference): array {
            foreach ($reference['observations'] as $index => $observation) {
                $reference['observations'][$index]['analyzer']['revision']['value'] = '99.0.0';
            }

            return $reference;
        }, 'not one observation from each analyzer of the run'];
    }

    #[DataProvider('brokenReferences')]
    public function test_reference_that_does_not_match_the_protocol_and_its_run_is_refused(\Closure $change, string $message): void
    {
        $directory = StudyFixture::write($this->directory, 1, static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned), referenceChanges: static fn (array $reference, array $planned): array => $planned['label'] === '03-'.$planned['implementation_id'] ? $change($reference) : $reference);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        StudyRecords::fromDirectory($directory);
    }

    public function test_session_whose_protocol_was_changed_after_planning_is_refused(): void
    {
        $directory = StudyFixture::write($this->directory, 1, static fn (RunFixture $run, array $planned) => self::signsBoth($run, $planned));
        file_put_contents($directory.'/protocol.json', str_replace('"seed":20261109', '"seed":1', (string) file_get_contents($directory.'/protocol.json')));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not the protocol its study.json was planned with');

        StudyRecords::fromDirectory($directory);
    }
}
