<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Run\DifferentialComparison;
use SignerPHP\PdfSigningQualification\Run\RunRecords;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class DifferentialComparisonTest extends TestCase
{
    private const string INPUT = 'qpdf:qpdf/qtest/qpdf/good17.pdf';

    private const array ANALYZERS = ['pyhanko' => '0.38.0', 'pdfsig' => '22.12.0', 'qpdf' => '11.3.0', 'cmp' => '3.8'];

    private const array VALID = ['pyhanko' => 'valid', 'pdfsig' => 'valid', 'qpdf' => 'valid', 'cmp' => 'valid'];

    private const array CLEAN_BASELINE = ['pyhanko' => 'not_applicable', 'pdfsig' => 'not_applicable', 'qpdf' => 'valid'];

    /**
     * @return list<RunFixture>
     */
    private static function runs(string $implementationId, string $hour): array
    {
        $revision = $implementationId === 'jsignpdf' ? ['kind' => 'version', 'value' => '3.2.1'] : ['kind' => 'commit', 'value' => str_repeat('a', 40)];

        return [
            (new RunFixture("run-20261107T{$hour}0000Z", self::ANALYZERS))->implementation($implementationId, $revision),
            (new RunFixture("run-20261107T{$hour}3000Z", self::ANALYZERS))->implementation($implementationId, $revision),
        ];
    }

    /**
     * @param  callable(RunFixture): void  $first
     * @param  callable(RunFixture): void  $second
     */
    private static function compare(callable $first, callable $second, ?callable $firstRepetition = null, ?callable $secondRepetition = null): array
    {
        $firstRuns = self::runs('pdf-signer', '12');
        $secondRuns = self::runs('jsignpdf', '13');
        $first($firstRuns[0]);
        ($firstRepetition ?? $first)($firstRuns[1]);
        $second($secondRuns[0]);
        ($secondRepetition ?? $second)($secondRuns[1]);

        $comparison = DifferentialComparison::compare(
            array_map(static fn (RunFixture $run) => $run->records(), $firstRuns),
            array_map(static fn (RunFixture $run) => $run->records(), $secondRuns),
            'failure_enriched',
        );
        self::assertSame([], (new SchemaValidator)->errors($comparison, DifferentialComparison::SCHEMA));

        return $comparison;
    }

    /**
     * @return array<string, array>
     */
    private static function items(array $comparison, string $sourceId = self::INPUT): array
    {
        foreach ($comparison['inputs'] as $input) {
            if ($input['source_id'] === $sourceId) {
                $items = [];
                foreach ($input['items'] as $item) {
                    $items[$item['subject'].(isset($item['analyzer_id']) ? ' '.$item['analyzer_id'] : '')] = $item;
                }

                return $items;
            }
        }
        self::fail("{$sourceId} is not in the comparison");
    }

    private static function signed(array $verdicts = self::VALID, array $baseline = self::CLEAN_BASELINE): callable
    {
        return static fn (RunFixture $run) => $run->succeeded(self::INPUT, $verdicts)->baselines(self::INPUT, $baseline);
    }

    private static function failedOn(array $baseline = self::CLEAN_BASELINE, string $outcome = 'failed'): callable
    {
        return static fn (RunFixture $run) => $run->failed(self::INPUT, $outcome)->baselines(self::INPUT, $baseline);
    }

    public function test_identical_valid_outcomes_are_agreements_and_identical_failures_are_shared(): void
    {
        $verdicts = ['pdfsig' => 'invalid'] + self::VALID;
        $items = self::items(self::compare(self::signed($verdicts), self::signed($verdicts)));

        self::assertSame('agreement', $items['capability']['classification']);
        self::assertSame('agreement', $items['execution']['classification']);
        self::assertSame('agreement', $items['verdict qpdf']['classification']);
        self::assertSame('shared_failure', $items['verdict pdfsig']['classification']);
    }

    public function test_a_verdict_that_fails_for_one_implementation_on_a_clean_input_is_a_defect_citing_its_observations(): void
    {
        $comparison = self::compare(self::signed(['qpdf' => 'invalid'] + self::VALID), self::signed());
        $item = self::items($comparison)['verdict qpdf'];

        self::assertSame('reproducible_implementation_defect', $item['classification']);
        self::assertSame('first', $item['defective_side']);
        self::assertSame(['invalid', 'invalid'], $item['first']);
        self::assertSame(['valid', 'valid'], $item['second']);
        self::assertCount(4, $item['observation_ids']);
        self::assertStringEndsWith('/qpdf', $item['observation_ids'][2]);
        self::assertStringContainsString('jsignpdf', $item['observation_ids'][2]);
        self::assertCount(4, $item['baseline_ids']);
        self::assertSame(1, $comparison['totals']['items']['reproducible_implementation_defect']);
        self::assertSame(1, $comparison['totals']['inputs_by_classification']['reproducible_implementation_defect']);
    }

    public function test_a_difference_seen_in_one_repetition_only_is_unresolved(): void
    {
        $item = self::items(self::compare(
            self::signed(['qpdf' => 'invalid'] + self::VALID),
            self::signed(),
            self::signed(),
        ))['verdict qpdf'];

        self::assertSame('unresolved_finding', $item['classification']);
        self::assertArrayNotHasKey('defective_side', $item);
        self::assertStringStartsWith('Not reproduced', $item['reason']);
    }

    public function test_a_failure_the_input_already_has_is_not_attributed_to_the_implementation(): void
    {
        $baseline = ['qpdf' => 'inconclusive'] + self::CLEAN_BASELINE;
        $item = self::items(self::compare(
            self::signed(['qpdf' => 'inconclusive'] + self::VALID, $baseline),
            self::signed(self::VALID, $baseline),
        ))['verdict qpdf'];

        self::assertSame('unresolved_finding', $item['classification']);
        self::assertStringContainsString('The input itself is `inconclusive` for `qpdf`', $item['reason']);
    }

    public function test_analyzers_of_one_property_that_disagree_on_an_output_make_a_validator_disagreement(): void
    {
        $item = self::items(self::compare(
            self::signed(['pyhanko' => 'inconclusive'] + self::VALID),
            self::signed(),
        ))['verdict pyhanko'];

        self::assertSame('validator_disagreement', $item['classification']);
        self::assertArrayNotHasKey('defective_side', $item);
    }

    public function test_an_analyzer_without_a_baseline_is_attributed_by_the_comparison_alone(): void
    {
        $item = self::items(self::compare(self::signed(), self::signed(['cmp' => 'invalid'] + self::VALID)))['verdict cmp'];

        self::assertSame('reproducible_implementation_defect', $item['classification']);
        self::assertSame('second', $item['defective_side']);
        self::assertSame([], $item['baseline_ids']);
    }

    public function test_an_input_that_one_implementation_does_not_sign_is_a_defect_only_when_the_input_is_clean(): void
    {
        $clean = self::items(self::compare(self::failedOn(), self::signed()));
        self::assertSame('reproducible_implementation_defect', $clean['execution']['classification']);
        self::assertSame('first', $clean['execution']['defective_side']);
        self::assertArrayNotHasKey('verdict qpdf', $clean);

        $damaged = ['qpdf' => 'inconclusive'] + self::CLEAN_BASELINE;
        $item = self::items(self::compare(self::failedOn($damaged), self::signed(self::VALID, $damaged)))['execution'];
        self::assertSame('unresolved_finding', $item['classification']);
        self::assertStringContainsString('`qpdf` `inconclusive`', $item['reason']);
    }

    public function test_both_implementations_failing_differently_is_unresolved(): void
    {
        $item = self::items(self::compare(self::failedOn(outcome: 'timed_out'), self::failedOn()))['execution'];

        self::assertSame('unresolved_finding', $item['classification']);
        self::assertStringStartsWith('Both implementations fail', $item['reason']);
    }

    private static function rejected(int $exitCode = 1, string $outcome = 'failed'): callable
    {
        return static fn (RunFixture $run) => $run->failed(self::INPUT, $outcome, $exitCode, 'expected_rejection')->baselines(self::INPUT, ['qpdf' => 'invalid'] + self::CLEAN_BASELINE);
    }

    private static function signedDespiteRejection(): callable
    {
        return static fn (RunFixture $run) => $run->succeeded(self::INPUT, self::VALID, capability: 'expected_rejection')->baselines(self::INPUT, ['qpdf' => 'invalid'] + self::CLEAN_BASELINE);
    }

    public function test_signing_an_expected_rejection_that_the_other_implementation_rejects_is_a_defect(): void
    {
        $items = self::items(self::compare(self::rejected(), self::signedDespiteRejection()));

        self::assertSame('agreement', $items['capability']['classification']);
        self::assertSame('reproducible_implementation_defect', $items['execution']['classification']);
        self::assertSame('second', $items['execution']['defective_side']);
    }

    public function test_an_exit_without_output_on_an_expected_rejection_is_not_an_agreement_with_a_rejection(): void
    {
        $item = self::items(self::compare(self::rejected(), self::rejected(exitCode: 0)))['execution'];
        self::assertSame('reproducible_implementation_defect', $item['classification']);
        self::assertSame('second', $item['defective_side']);

        $reversed = self::items(self::compare(self::rejected(exitCode: 0), self::rejected()))['execution'];
        self::assertSame('reproducible_implementation_defect', $reversed['classification']);
        self::assertSame('first', $reversed['defective_side']);
    }

    public function test_both_implementations_rejecting_as_expected_agree(): void
    {
        self::assertSame('agreement', self::items(self::compare(self::rejected(), self::rejected(exitCode: 2)))['execution']['classification']);
    }

    public function test_differences_where_both_implementations_fail_the_declared_rejection_stay_expected_rejections(): void
    {
        $item = self::items(self::compare(self::rejected(outcome: 'timed_out'), self::signedDespiteRejection()))['execution'];

        self::assertSame('expected_rejection', $item['classification']);
    }

    public function test_a_rejection_seen_in_one_run_only_is_not_reproduced(): void
    {
        $item = self::items(self::compare(self::rejected(), self::signedDespiteRejection(), secondRepetition: self::rejected()))['execution'];

        self::assertSame('unresolved_finding', $item['classification']);
        self::assertStringStartsWith('Not reproduced', $item['reason']);
    }

    public function test_inputs_that_neither_implementation_signs_are_still_compared(): void
    {
        $items = self::items(self::compare(self::failedOn(), self::failedOn(outcome: 'timed_out')));

        self::assertSame('unresolved_finding', $items['execution']['classification']);
        self::assertArrayNotHasKey('verdict qpdf', $items);
    }

    public function test_baselines_under_another_policy_are_refused(): void
    {
        $first = array_map(static fn (RunFixture $run) => $run->succeeded(self::INPUT, self::VALID)->baselines(self::INPUT, self::CLEAN_BASELINE)->records(), self::runs('pdf-signer', '12'));
        $second = self::runs('jsignpdf', '13');
        $records = $second[0]->succeeded(self::INPUT, self::VALID)->baselines(self::INPUT, self::CLEAN_BASELINE)->records();
        $baselines = $records->baselines ?? [];
        $baselines[0]['policy'] = ['id' => 'another-policy'];

        $this->expectExceptionMessage('analyzer policies');
        DifferentialComparison::compare($first, [new RunRecords($records->run, $records->attempts, $records->observations, null, $baselines), $second[1]->succeeded(self::INPUT, self::VALID)->baselines(self::INPUT, self::CLEAN_BASELINE)->records()], 'failure_enriched');
    }

    public function test_a_capability_that_differs_between_the_implementations_is_a_capability_mismatch(): void
    {
        $items = self::items(self::compare(
            static fn (RunFixture $run) => $run->unsupported(self::INPUT)->baselines(self::INPUT, self::CLEAN_BASELINE),
            self::signed(),
        ));

        self::assertSame('capability_mismatch', $items['capability']['classification']);
        self::assertSame('capability_mismatch', $items['execution']['classification']);
    }

    public function test_an_unavailable_input_has_no_items(): void
    {
        $comparison = self::compare(
            static fn (RunFixture $run) => $run->unavailable('qpdf:missing.pdf')->succeeded(self::INPUT, self::VALID)->baselines(self::INPUT, self::CLEAN_BASELINE),
            static fn (RunFixture $run) => $run->unavailable('qpdf:missing.pdf')->succeeded(self::INPUT, self::VALID)->baselines(self::INPUT, self::CLEAN_BASELINE),
        );

        self::assertSame(['paired' => 1, 'unavailable' => 1, 'input_changed' => 0], $comparison['totals']['inputs']);
        self::assertSame([], self::items($comparison, 'qpdf:missing.pdf'));
        self::assertSame(1, $comparison['totals']['inputs_by_classification']['agreement']);
    }

    public function test_each_run_reports_its_complete_denominator(): void
    {
        $comparison = self::compare(
            static fn (RunFixture $run) => $run->unavailable('qpdf:missing.pdf')->failed('qpdf:bad.pdf')->succeeded(self::INPUT, self::VALID),
            static fn (RunFixture $run) => $run->unavailable('qpdf:missing.pdf')->succeeded('qpdf:bad.pdf', self::VALID)->succeeded(self::INPUT, self::VALID),
        );

        self::assertSame(
            ['unavailable' => 1, 'unsupported' => 0, 'rejected_as_expected' => 0, 'not_signed' => 1, 'signed' => 1, 'total' => 3],
            $comparison['first']['runs'][1]['coverage'],
        );
        self::assertSame(2, $comparison['second']['runs'][0]['coverage']['signed']);
        self::assertSame('jsignpdf', $comparison['second']['implementation']['id']);
    }

    /**
     * @return array<string, array{callable(list<RunFixture>, list<RunFixture>): void, string}>
     */
    public static function incomparableRuns(): array
    {
        return [
            'another scenario' => [static function (array $first, array $second): void {
                $second[1]->run['scenario_id'] = 'basic-invisible-sha512';
            }, 'scenario'],
            'another analyzer revision' => [static function (array $first, array $second): void {
                $second[0]->run['analyzers'][1]['revision']['value'] = '24.02.0';
            }, 'analyzers'],
            'another source configuration' => [static function (array $first, array $second): void {
                $first[1]->run['source_configuration']['sha256'] = str_repeat('f', 64);
            }, 'source configuration'],
            'other limits' => [static function (array $first, array $second): void {
                $second[1]->run['environment']['resource_limits']['timeout_seconds'] = 60;
            }, 'resource limits'],
            'other expected rejections' => [static function (array $first, array $second): void {
                $second[0]->run['expected_rejections']['byte_size'] = 1;
            }, 'expected rejections'],
            'repetitions of different revisions' => [static function (array $first, array $second): void {
                $first[1]->implementationCommit(str_repeat('b', 40));
            }, 'do not repeat each other'],
            'the same implementation on both sides' => [static function (array $first, array $second): void {
                foreach ($second as $run) {
                    $run->implementation('pdf-signer', ['kind' => 'commit', 'value' => str_repeat('a', 40)]);
                }
            }, 'compare-runs'],
            'the same run twice' => [static function (array $first, array $second): void {
                $first[1]->run['id'] = $first[0]->run['id'];
            }, 'more than once'],
        ];
    }

    #[DataProvider('incomparableRuns')]
    public function test_runs_that_differ_in_anything_but_the_implementation_are_refused(callable $change, string $message): void
    {
        $first = self::runs('pdf-signer', '12');
        $second = self::runs('jsignpdf', '13');
        $change($first, $second);
        foreach ([...$first, ...$second] as $run) {
            $run->succeeded(self::INPUT, self::VALID);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        DifferentialComparison::compare(
            array_map(static fn (RunFixture $run) => $run->records(), $first),
            array_map(static fn (RunFixture $run) => $run->records(), $second),
            'failure_enriched',
        );
    }

    public function test_runs_without_baselines_are_refused(): void
    {
        $first = array_map(static fn (RunFixture $run) => $run->succeeded(self::INPUT, self::VALID)->records(), self::runs('pdf-signer', '12'));
        $second = self::runs('jsignpdf', '13');
        $records = $second[0]->succeeded(self::INPUT, self::VALID)->records();
        $withoutBaselines = new RunRecords($records->run, $records->attempts, $records->observations);

        $this->expectExceptionMessage('has no baselines.json');
        DifferentialComparison::compare($first, [$withoutBaselines, $second[1]->succeeded(self::INPUT, self::VALID)->records()], 'failure_enriched');
    }

    public function test_runs_without_a_paired_input_are_refused(): void
    {
        $this->expectExceptionMessage('nothing to compare');
        self::compare(
            static fn (RunFixture $run) => $run->unavailable(self::INPUT),
            static fn (RunFixture $run) => $run->unavailable(self::INPUT),
        );
    }
}
