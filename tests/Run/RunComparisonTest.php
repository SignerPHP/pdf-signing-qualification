<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Run\RunComparison;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class RunComparisonTest extends TestCase
{
    private const string INPUT = 'qpdf:qpdf/qtest/qpdf/good17.pdf';

    private const array VALID = ['pdfsig' => 'valid', 'qpdf' => 'valid'];

    private static function reference(): RunFixture
    {
        return new RunFixture('run-20261107T120000Z');
    }

    private static function candidate(): RunFixture
    {
        return new RunFixture('run-20261107T130000Z');
    }

    private static function compare(RunFixture $reference, RunFixture $candidate): array
    {
        $comparison = RunComparison::compare($reference->records(), $candidate->records());
        self::assertSame([], (new SchemaValidator)->errors($comparison, RunComparison::SCHEMA));

        return $comparison;
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    private static function items(array $comparison, string $sourceId = self::INPUT): array
    {
        foreach ($comparison['inputs'] as $input) {
            if ($input['source_id'] === $sourceId) {
                $items = [];
                foreach ($input['items'] as $item) {
                    $items[$item['subject'].(isset($item['analyzer_id']) ? ' '.$item['analyzer_id'] : '')] = [$item['reference'], $item['candidate'], $item['change']];
                }

                return $items;
            }
        }
        self::fail("{$sourceId} is not in the comparison");
    }

    private static function input(array $comparison, string $sourceId): array
    {
        foreach ($comparison['inputs'] as $input) {
            if ($input['source_id'] === $sourceId) {
                return $input;
            }
        }
        self::fail("{$sourceId} is not in the comparison");
    }

    public function test_two_identical_runs_with_different_output_hashes_have_no_change(): void
    {
        $reference = self::reference()
            ->succeeded(self::INPUT, self::VALID, 'first signature')
            ->succeeded('qpdf:qpdf/qtest/qpdf/damaged.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'inconclusive'], 'first signature')
            ->failed('qpdf:qpdf/qtest/qpdf/broken.pdf')
            ->unsupported('qpdf:qpdf/qtest/qpdf/encrypted.pdf');
        $candidate = self::candidate()
            ->succeeded(self::INPUT, self::VALID, 'second signature')
            ->succeeded('qpdf:qpdf/qtest/qpdf/damaged.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'inconclusive'], 'second signature')
            ->failed('qpdf:qpdf/qtest/qpdf/broken.pdf')
            ->unsupported('qpdf:qpdf/qtest/qpdf/encrypted.pdf');

        $comparison = self::compare($reference, $candidate);

        self::assertSame([], $comparison['differences']);
        self::assertSame(['paired' => 4, 'only_in_reference' => 0, 'only_in_candidate' => 0, 'unavailable' => 0, 'input_changed' => 0], $comparison['totals']['inputs']);
        self::assertSame(['introduced' => 0, 'fixed' => 0, 'persistent' => 4, 'changed' => 0, 'unchanged' => 12], $comparison['totals']['changes']);
        self::assertFalse(RunComparison::hasNewFailures($comparison));
        self::assertSame([
            'capability' => ['supported', 'supported', 'unchanged'],
            'execution' => ['succeeded', 'succeeded', 'unchanged'],
            'verdict pdfsig' => ['valid', 'valid', 'unchanged'],
            'verdict qpdf' => ['valid', 'valid', 'unchanged'],
        ], self::items($comparison));
        self::assertSame('run-20261107T120000Z/basic-invisible-sha256/pdf-signer/'.self::INPUT, self::input($comparison, self::INPUT)['reference']['attempt_id']);
        self::assertSame('run-20261107T130000Z/basic-invisible-sha256/pdf-signer/'.self::INPUT, self::input($comparison, self::INPUT)['candidate']['attempt_id']);
    }

    public static function classifiedVerdicts(): iterable
    {
        yield 'valid to invalid is introduced' => ['valid', 'invalid', 'introduced'];
        yield 'valid to inconclusive is introduced' => ['valid', 'inconclusive', 'introduced'];
        yield 'not applicable to invalid is introduced' => ['not_applicable', 'invalid', 'introduced'];
        yield 'not applicable to inconclusive is introduced' => ['not_applicable', 'inconclusive', 'introduced'];
        yield 'invalid to valid is fixed' => ['invalid', 'valid', 'fixed'];
        yield 'inconclusive to valid is fixed' => ['inconclusive', 'valid', 'fixed'];
        yield 'invalid to invalid is persistent' => ['invalid', 'invalid', 'persistent'];
        yield 'inconclusive to invalid is persistent' => ['inconclusive', 'invalid', 'persistent'];
        yield 'invalid to inconclusive is persistent, not fixed' => ['invalid', 'inconclusive', 'persistent'];
        yield 'invalid to not applicable is changed, not fixed' => ['invalid', 'not_applicable', 'changed'];
        yield 'valid to not applicable is changed' => ['valid', 'not_applicable', 'changed'];
    }

    #[DataProvider('classifiedVerdicts')]
    public function test_each_verdict_is_classified_by_whether_the_summary_of_either_run_lists_it_as_a_failure(string $referenceVerdict, string $candidateVerdict, string $change): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, ['pdfsig' => $referenceVerdict, 'qpdf' => 'valid']),
            self::candidate()->succeeded(self::INPUT, ['pdfsig' => $candidateVerdict, 'qpdf' => 'valid']),
        );

        self::assertSame([$referenceVerdict, $candidateVerdict, $change], self::items($comparison)['verdict pdfsig']);
        self::assertSame(['valid', 'valid', 'unchanged'], self::items($comparison)['verdict qpdf']);
        self::assertSame($change === 'introduced', RunComparison::hasNewFailures($comparison));
    }

    public function test_an_execution_that_starts_failing_is_introduced_and_its_verdicts_are_changed(): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID),
            self::candidate()->failed(self::INPUT, 'timed_out', null),
        );

        self::assertSame([
            'capability' => ['supported', 'supported', 'unchanged'],
            'execution' => ['succeeded', 'timed_out', 'introduced'],
            'verdict pdfsig' => ['valid', 'not_evaluated', 'changed'],
            'verdict qpdf' => ['valid', 'not_evaluated', 'changed'],
        ], self::items($comparison));
        self::assertTrue(RunComparison::hasNewFailures($comparison));
    }

    public function test_an_execution_that_starts_succeeding_is_fixed_and_a_new_invalid_verdict_is_introduced(): void
    {
        $comparison = self::compare(
            self::reference()->failed(self::INPUT),
            self::candidate()->succeeded(self::INPUT, ['pdfsig' => 'invalid', 'qpdf' => 'valid']),
        );

        self::assertSame([
            'capability' => ['supported', 'supported', 'unchanged'],
            'execution' => ['failed', 'succeeded', 'fixed'],
            'verdict pdfsig' => ['not_evaluated', 'invalid', 'introduced'],
            'verdict qpdf' => ['not_evaluated', 'valid', 'changed'],
        ], self::items($comparison));
        self::assertTrue(RunComparison::hasNewFailures($comparison));
    }

    public function test_a_failure_that_changes_kind_is_persistent(): void
    {
        $comparison = self::compare(
            self::reference()->failed(self::INPUT),
            self::candidate()->failed(self::INPUT, 'timed_out', null),
        );

        self::assertSame(['failed', 'timed_out', 'persistent'], self::items($comparison)['execution']);
        self::assertFalse(RunComparison::hasNewFailures($comparison));
    }

    public function test_a_rejection_that_was_expected_is_not_a_failure_in_either_direction(): void
    {
        $comparison = self::compare(
            self::reference()
                ->failed(self::INPUT, capability: 'expected_rejection')
                ->failed('qpdf:qpdf/qtest/qpdf/not-a-pdf.pdf'),
            self::candidate()
                ->failed(self::INPUT, capability: 'expected_rejection')
                ->failed('qpdf:qpdf/qtest/qpdf/not-a-pdf.pdf', capability: 'expected_rejection'),
        );

        self::assertSame(['failed', 'failed', 'unchanged'], self::items($comparison)['execution']);
        self::assertSame([
            'capability' => ['supported', 'expected_rejection', 'changed'],
            'execution' => ['failed', 'failed', 'changed'],
            'verdict pdfsig' => ['not_evaluated', 'not_evaluated', 'unchanged'],
            'verdict qpdf' => ['not_evaluated', 'not_evaluated', 'unchanged'],
        ], self::items($comparison, 'qpdf:qpdf/qtest/qpdf/not-a-pdf.pdf'));
    }

    public function test_an_expected_rejection_that_did_not_reject_is_classified_by_its_outcome(): void
    {
        $comparison = self::compare(
            self::reference()
                ->failed(self::INPUT, capability: 'expected_rejection')
                ->failed('qpdf:qpdf/qtest/qpdf/not-a-pdf.pdf', capability: 'expected_rejection'),
            self::candidate()
                ->failed(self::INPUT, 'timed_out', null, 'expected_rejection')
                ->succeeded('qpdf:qpdf/qtest/qpdf/not-a-pdf.pdf', self::VALID, capability: 'expected_rejection'),
        );

        self::assertSame(['failed', 'timed_out', 'introduced'], self::items($comparison)['execution']);
        self::assertSame(['failed', 'succeeded', 'introduced'], self::items($comparison, 'qpdf:qpdf/qtest/qpdf/not-a-pdf.pdf')['execution']);
    }

    public function test_an_expected_rejection_that_rejects_again_after_succeeding_is_a_change_not_a_fix(): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID, capability: 'expected_rejection'),
            self::candidate()->failed(self::INPUT, capability: 'expected_rejection'),
        );

        self::assertSame(['succeeded', 'failed', 'changed'], self::items($comparison)['execution']);
        self::assertFalse(RunComparison::hasNewFailures($comparison));
    }

    public function test_an_input_that_is_no_longer_supported_is_a_new_failure_of_its_execution(): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID),
            self::candidate()->unsupported(self::INPUT),
        );

        self::assertSame([
            'capability' => ['supported', 'unsupported', 'changed'],
            'execution' => ['succeeded', 'not_run', 'introduced'],
            'verdict pdfsig' => ['valid', 'not_evaluated', 'changed'],
            'verdict qpdf' => ['valid', 'not_evaluated', 'changed'],
        ], self::items($comparison));
        self::assertTrue(RunComparison::hasNewFailures($comparison));
    }

    public function test_inputs_present_in_one_run_unavailable_in_one_or_changed_are_reported_and_never_fixed(): void
    {
        $reference = self::reference()
            ->failed('qpdf:only-in-reference.pdf')
            ->failed('qpdf:unavailable-in-candidate.pdf')
            ->unavailable('qpdf:unavailable-in-reference.pdf')
            ->failed('qpdf:changed.pdf')
            ->succeeded(self::INPUT, self::VALID);
        $candidate = self::candidate()
            ->succeeded(self::INPUT, self::VALID)
            ->unavailable('qpdf:unavailable-in-candidate.pdf')
            ->succeeded('qpdf:unavailable-in-reference.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'valid'])
            ->succeeded('qpdf:changed.pdf', self::VALID, inputContent: 'another input')
            ->succeeded('qpdf:only-in-candidate.pdf', ['pdfsig' => 'invalid', 'qpdf' => 'valid']);

        $comparison = self::compare($reference, $candidate);

        self::assertSame(['paired' => 1, 'only_in_reference' => 1, 'only_in_candidate' => 1, 'unavailable' => 2, 'input_changed' => 1], $comparison['totals']['inputs']);
        self::assertSame(['introduced' => 0, 'fixed' => 0, 'persistent' => 0, 'changed' => 0, 'unchanged' => 4], $comparison['totals']['changes']);
        self::assertFalse(RunComparison::hasNewFailures($comparison));
        self::assertSame(
            [
                'qpdf:only-in-reference.pdf' => 'only_in_reference',
                'qpdf:unavailable-in-candidate.pdf' => 'unavailable',
                'qpdf:unavailable-in-reference.pdf' => 'unavailable',
                'qpdf:changed.pdf' => 'input_changed',
                self::INPUT => 'paired',
                'qpdf:only-in-candidate.pdf' => 'only_in_candidate',
            ],
            array_column($comparison['inputs'], 'status', 'source_id'),
        );
        $onlyInReference = self::input($comparison, 'qpdf:only-in-reference.pdf');
        self::assertSame($reference->attemptId('qpdf:only-in-reference.pdf'), $onlyInReference['reference']['attempt_id']);
        self::assertNull($onlyInReference['candidate']);
        self::assertSame('unavailable', self::input($comparison, 'qpdf:unavailable-in-candidate.pdf')['candidate']['input']['status']);
        self::assertSame(hash('sha256', 'another input'), self::input($comparison, 'qpdf:changed.pdf')['candidate']['input']['sha256']);
    }

    public static function runsWithoutAnyPair(): iterable
    {
        yield 'different scenarios' => [
            self::reference()->succeeded(self::INPUT, self::VALID),
            (new RunFixture('run-20261107T130000Z', scenarioId: 'visible-sha256'))->succeeded(self::INPUT, ['pdfsig' => 'invalid', 'qpdf' => 'valid']),
        ];
        yield 'input unavailable in the candidate run' => [
            self::reference()->succeeded(self::INPUT, self::VALID),
            self::candidate()->unavailable(self::INPUT),
        ];
        yield 'no input in common' => [
            self::reference()->succeeded(self::INPUT, self::VALID),
            self::candidate()->succeeded('qpdf:qpdf/qtest/qpdf/other.pdf', self::VALID),
        ];
    }

    #[DataProvider('runsWithoutAnyPair')]
    public function test_runs_without_any_paired_input_are_refused(RunFixture $reference, RunFixture $candidate): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The runs run-20261107T120000Z and run-20261107T130000Z have no available input in common under the same scenario and with the same content, so there is nothing to compare');

        RunComparison::compare($reference->records(), $candidate->records());
    }

    public function test_differences_in_implementation_analyzers_policies_and_configuration_are_stated(): void
    {
        $reference = (new RunFixture('run-20261107T120000Z', ['pdfsig' => '22.12.0', 'qpdf' => '11.3.0', 'pyhanko' => '0.38.0']))
            ->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid', 'pyhanko' => 'invalid'])
            ->policy('pdfsig', 'pdfsig-no-certificate');
        $candidate = (new RunFixture('run-20261107T130000Z', ['pdfsig' => '24.02.0', 'qpdf' => '11.3.0', 'cmp' => '3.8']))
            ->implementationCommit(str_repeat('b', 40))
            ->succeeded(self::INPUT, ['pdfsig' => 'valid', 'qpdf' => 'valid', 'cmp' => 'invalid'])
            ->policy('pdfsig', 'pdfsig-no-certificate-v2');
        $candidate->run['source_configuration']['sha256'] = str_repeat('f', 64);
        $candidate->run['expected_rejections']['byte_size'] = 400;
        $candidate->run['environment']['container_image']['id'] = 'sha256:'.str_repeat('9', 64);
        $candidate->run['environment']['resource_limits']['timeout_seconds'] = 60;
        $candidate->run['implementation_runtime'] = ['engine' => 'OpenPDF'];
        $candidate->compatibility(['status' => 'incompatible', 'reason' => 'pdf-signer does not list --no-default-timestamp in its help']);

        $comparison = self::compare($reference, $candidate);

        self::assertSame([
            ['subject' => 'implementation', 'reference' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('a', 40)]], 'candidate' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('b', 40)]]],
            ['subject' => 'implementation_runtime', 'reference' => null, 'candidate' => ['engine' => 'OpenPDF']],
            ['subject' => 'implementation_compatibility', 'reference' => null, 'candidate' => ['status' => 'incompatible', 'reason' => 'pdf-signer does not list --no-default-timestamp in its help']],
            ['subject' => 'analyzer', 'analyzer_id' => 'pdfsig', 'reference' => ['kind' => 'version', 'value' => '22.12.0'], 'candidate' => ['kind' => 'version', 'value' => '24.02.0']],
            ['subject' => 'analyzer', 'analyzer_id' => 'pyhanko', 'reference' => ['kind' => 'version', 'value' => '0.38.0'], 'candidate' => null],
            ['subject' => 'analyzer', 'analyzer_id' => 'cmp', 'reference' => null, 'candidate' => ['kind' => 'version', 'value' => '3.8']],
            ['subject' => 'analyzer_policy', 'analyzer_id' => 'pdfsig', 'reference' => [['id' => 'pdfsig-no-certificate']], 'candidate' => [['id' => 'pdfsig-no-certificate-v2']]],
            ['subject' => 'source_configuration', 'reference' => ['sha256' => str_repeat('d', 64), 'byte_size' => 13_321], 'candidate' => ['sha256' => str_repeat('f', 64), 'byte_size' => 13_321]],
            ['subject' => 'expected_rejections', 'reference' => ['sha256' => str_repeat('e', 64), 'byte_size' => 373], 'candidate' => ['sha256' => str_repeat('e', 64), 'byte_size' => 400]],
            ['subject' => 'container_image', 'reference' => ['status' => 'available', 'id' => 'sha256:'.str_repeat('c', 64)], 'candidate' => ['status' => 'available', 'id' => 'sha256:'.str_repeat('9', 64)]],
            ['subject' => 'resource_limits', 'reference' => $reference->run['environment']['resource_limits'], 'candidate' => $candidate->run['environment']['resource_limits']],
        ], $comparison['differences']);
        self::assertSame([
            'capability' => ['supported', 'supported', 'unchanged'],
            'execution' => ['succeeded', 'succeeded', 'unchanged'],
            'verdict pdfsig' => ['valid', 'valid', 'unchanged'],
            'verdict qpdf' => ['valid', 'valid', 'unchanged'],
        ], self::items($comparison));
        self::assertFalse(RunComparison::hasNewFailures($comparison));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    private static function regressionItems(array $comparison, string $caseId = 'hybrid-xref'): array
    {
        $items = [];
        foreach (self::regression($comparison, $caseId)['items'] as $item) {
            $items[$item['subject'].(isset($item['analyzer_id']) ? ' '.$item['analyzer_id'] : '')] = [$item['reference'], $item['candidate'], $item['change']];
        }

        return $items;
    }

    private static function regression(array $comparison, string $caseId): array
    {
        foreach ($comparison['regressions'] as $regression) {
            if ($regression['case_id'] === $caseId) {
                return $regression;
            }
        }
        self::fail("{$caseId} is not in the comparison");
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function conditionChanges(): iterable
    {
        yield 'present in both runs' => ['present', 'present', 'persistent'];
        yield 'present only in the candidate run' => ['absent', 'present', 'introduced'];
        yield 'no longer present' => ['present', 'absent', 'fixed'];
        yield 'absent from both runs' => ['absent', 'absent', 'unchanged'];
        yield 'present, then undetermined' => ['present', 'undetermined', 'changed'];
        yield 'not evaluated, then present' => ['not_evaluated', 'present', 'introduced'];
        yield 'present, then not evaluated' => ['present', 'not_evaluated', 'changed'];
    }

    #[DataProvider('conditionChanges')]
    public function test_each_regression_condition_is_classified_by_whether_the_defect_is_present(string $referenceCondition, string $candidateCondition, string $change): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID)->regression('hybrid-xref', $referenceCondition, self::VALID),
            self::candidate()->succeeded(self::INPUT, self::VALID)->regression('hybrid-xref', $candidateCondition, self::VALID),
        );

        self::assertSame('paired', self::regression($comparison, 'hybrid-xref')['status']);
        self::assertSame([$referenceCondition, $candidateCondition, $change], self::regressionItems($comparison)['condition']);
        self::assertSame($change === 'introduced', RunComparison::hasNewFailures($comparison));
        self::assertSame(0, $comparison['totals']['changes']['introduced']);
        self::assertSame($change === 'introduced' ? 1 : 0, $comparison['totals']['regression_changes']['introduced']);
    }

    public function test_a_synthetic_case_compares_its_own_attempt_like_an_input(): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID)->regression('hybrid-xref', 'present', ['pdfsig' => 'valid', 'qpdf' => 'valid']),
            self::candidate()->succeeded(self::INPUT, self::VALID)->regression('hybrid-xref', 'not_evaluated', outcome: 'failed'),
        );

        self::assertSame([
            'capability' => ['supported', 'supported', 'unchanged'],
            'execution' => ['succeeded', 'failed', 'introduced'],
            'verdict pdfsig' => ['valid', 'not_evaluated', 'changed'],
            'verdict qpdf' => ['valid', 'not_evaluated', 'changed'],
            'condition' => ['present', 'not_evaluated', 'changed'],
        ], self::regressionItems($comparison));
        self::assertSame(['input' => 0, 'regression' => 1], ['input' => $comparison['totals']['changes']['introduced'], 'regression' => $comparison['totals']['regression_changes']['introduced']]);
        self::assertTrue(RunComparison::hasNewFailures($comparison));
        self::assertSame([self::INPUT], array_column($comparison['inputs'], 'source_id'));
    }

    public function test_a_case_on_a_declared_input_compares_only_its_condition(): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID)->regressionOnInput('hybrid-xref', self::INPUT, 'present'),
            self::candidate()->succeeded(self::INPUT, self::VALID)->regressionOnInput('hybrid-xref', self::INPUT, 'absent'),
        );

        self::assertSame(['condition' => ['present', 'absent', 'fixed']], self::regressionItems($comparison));
    }

    public function test_cases_missing_from_a_run_or_not_replayed_in_one_are_reported_without_items(): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID)->regression('removed-case', 'present')->regressionNotReplayed('other-scenario-case'),
            self::candidate()->succeeded(self::INPUT, self::VALID)->regression('new-case', 'present')->regression('other-scenario-case', 'present'),
        );

        self::assertSame(
            ['removed-case' => 'only_in_reference', 'other-scenario-case' => 'not_replayed', 'new-case' => 'only_in_candidate'],
            array_column($comparison['regressions'], 'status', 'case_id'),
        );
        self::assertSame([[], [], []], array_column($comparison['regressions'], 'items'));
        self::assertFalse(RunComparison::hasNewFailures($comparison));
    }

    public function test_a_synthetic_input_that_changed_is_not_compared(): void
    {
        $comparison = self::compare(
            self::reference()->succeeded(self::INPUT, self::VALID)->regression('hybrid-xref', 'absent'),
            self::candidate()->succeeded(self::INPUT, self::VALID)->regression('hybrid-xref', 'present', inputContent: 'regenerated input'),
        );

        self::assertSame('input_changed', self::regression($comparison, 'hybrid-xref')['status']);
        self::assertSame([], self::regression($comparison, 'hybrid-xref')['items']);
        self::assertFalse(RunComparison::hasNewFailures($comparison));
    }

    public function test_runs_without_regression_cases_have_none_to_compare(): void
    {
        $comparison = self::compare(self::reference()->succeeded(self::INPUT, self::VALID), self::candidate()->succeeded(self::INPUT, self::VALID));

        self::assertSame([], $comparison['regressions']);
        self::assertSame(['introduced' => 0, 'fixed' => 0, 'persistent' => 0, 'changed' => 0, 'unchanged' => 0], $comparison['totals']['regression_changes']);
    }
}
