<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class ComparisonSchemaTest extends SchemaTestCase
{
    private const string SOURCE_ID = 'qpdf:qpdf/qtest/qpdf/good17.pdf';

    protected static function schemaPath(): string
    {
        return 'v1/comparison.schema.json';
    }

    private static function side(string $runId, string $status = 'available'): array
    {
        return [
            'attempt_id' => "{$runId}/basic-invisible-sha256/pdf-signer/".self::SOURCE_ID,
            'input' => $status === 'available'
                ? ['status' => 'available', 'source_id' => self::SOURCE_ID, 'sha256' => str_repeat('a', 64), 'byte_size' => 1024]
                : ['status' => 'unavailable', 'source_id' => self::SOURCE_ID, 'reason' => 'missing_upstream', 'expected_sha256' => str_repeat('a', 64), 'message' => 'HTTP 404'],
        ];
    }

    protected static function validDocument(): array
    {
        $run = static fn (string $runId, string $commit): array => [
            'run_id' => $runId,
            'scenario_id' => 'basic-invisible-sha256',
            'implementation' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat($commit, 40)]],
            'analyzers' => [['id' => 'qpdf', 'revision' => ['kind' => 'version', 'value' => '11.3.0']]],
        ];

        return [
            'schema' => self::schemaId(),
            'reference' => $run('run-20261107T120000Z', 'a'),
            'candidate' => $run('run-20261107T130000Z', 'b'),
            'differences' => [
                ['subject' => 'implementation', 'reference' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('a', 40)]], 'candidate' => ['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('b', 40)]]],
                ['subject' => 'analyzer', 'analyzer_id' => 'pyhanko', 'reference' => ['kind' => 'version', 'value' => '0.38.0'], 'candidate' => null],
            ],
            'totals' => [
                'inputs' => ['paired' => 1, 'only_in_reference' => 0, 'only_in_candidate' => 0, 'unavailable' => 0, 'input_changed' => 0],
                'changes' => ['introduced' => 1, 'fixed' => 0, 'persistent' => 0, 'changed' => 0, 'unchanged' => 2],
                'regression_changes' => ['introduced' => 0, 'fixed' => 1, 'persistent' => 0, 'changed' => 0, 'unchanged' => 0],
            ],
            'inputs' => [[
                'source_id' => self::SOURCE_ID,
                'scenario_id' => 'basic-invisible-sha256',
                'status' => 'paired',
                'reference' => self::side('run-20261107T120000Z'),
                'candidate' => self::side('run-20261107T130000Z'),
                'items' => [
                    ['subject' => 'capability', 'reference' => 'supported', 'candidate' => 'supported', 'change' => 'unchanged'],
                    ['subject' => 'execution', 'reference' => 'succeeded', 'candidate' => 'succeeded', 'change' => 'unchanged'],
                    ['subject' => 'verdict', 'analyzer_id' => 'qpdf', 'reference' => 'valid', 'candidate' => 'invalid', 'change' => 'introduced'],
                ],
            ]],
            'regressions' => [[
                'case_id' => 'hybrid-xref',
                'status' => 'paired',
                'reference' => self::regressionSide('run-20261107T120000Z', 'present'),
                'candidate' => self::regressionSide('run-20261107T130000Z', 'absent'),
                'items' => [
                    ['subject' => 'condition', 'reference' => 'present', 'candidate' => 'absent', 'change' => 'fixed'],
                ],
            ]],
        ];
    }

    private static function regressionSide(string $runId, string $condition): array
    {
        return [
            'definition' => ['sha256' => str_repeat('a', 64), 'byte_size' => 600],
            'attempt_id' => "{$runId}/basic-invisible-sha256/pdf-signer/regression:hybrid-xref.pdf",
            'condition' => ['status' => $condition, 'evidence' => "The condition is {$condition}"],
        ];
    }

    private static function withRegression(callable $mutation): array
    {
        return self::mutate(function (array $comparison) use ($mutation): array {
            $comparison['regressions'][0] = $mutation($comparison['regressions'][0]);

            return $comparison;
        });
    }

    public function test_a_paired_regression_case_has_exactly_one_condition_item(): void
    {
        self::assertInvalid(self::withRegression(static function (array $regression): array {
            $regression['items'] = [];

            return $regression;
        }));
        self::assertInvalid(self::withRegression(static function (array $regression): array {
            $regression['items'][] = $regression['items'][0];

            return $regression;
        }));
        self::assertInvalid(self::withRegression(static function (array $regression): array {
            $regression['items'][0]['candidate'] = 'valid';

            return $regression;
        }));
    }

    public function test_a_regression_case_not_replayed_in_both_runs_has_no_item(): void
    {
        $notReplayed = static function (array $regression): array {
            $regression['status'] = 'not_replayed';
            $regression['candidate'] = ['definition' => ['sha256' => str_repeat('a', 64), 'byte_size' => 600], 'attempt_id' => null, 'condition' => ['status' => 'not_evaluated', 'evidence' => 'The case runs under scenario other-scenario']];

            return $regression;
        };
        self::assertInvalid(self::withRegression($notReplayed));
        self::assertValid(self::withRegression(static function (array $regression) use ($notReplayed): array {
            $regression = $notReplayed($regression);
            $regression['items'] = [];

            return $regression;
        }));
        self::assertInvalid(self::withRegression(static function (array $regression): array {
            $regression['candidate']['attempt_id'] = null;

            return $regression;
        }));
    }

    public function test_a_regression_case_in_one_run_only_has_no_side_in_the_other(): void
    {
        self::assertValid(self::withRegression(static function (array $regression): array {
            return ['case_id' => 'hybrid-xref', 'status' => 'only_in_candidate', 'reference' => null, 'candidate' => $regression['candidate'], 'items' => []];
        }));
        self::assertInvalid(self::withRegression(static function (array $regression): array {
            return ['case_id' => 'hybrid-xref', 'status' => 'only_in_candidate', 'reference' => $regression['reference'], 'candidate' => $regression['candidate'], 'items' => []];
        }));
    }

    private static function withInput(callable $mutation): array
    {
        return self::mutate(function (array $comparison) use ($mutation): array {
            $comparison['inputs'][0] = $mutation($comparison['inputs'][0]);

            return $comparison;
        });
    }

    public static function unpairedInputs(): iterable
    {
        yield 'only in the reference run' => ['only_in_reference', self::side('run-20261107T120000Z'), null];
        yield 'only in the candidate run' => ['only_in_candidate', null, self::side('run-20261107T130000Z')];
        yield 'unavailable in the reference run' => ['unavailable', self::side('run-20261107T120000Z', 'unavailable'), self::side('run-20261107T130000Z')];
        yield 'unavailable in the candidate run' => ['unavailable', self::side('run-20261107T120000Z'), self::side('run-20261107T130000Z', 'unavailable')];
        yield 'input changed between the runs' => ['input_changed', self::side('run-20261107T120000Z'), self::side('run-20261107T130000Z')];
    }

    #[DataProvider('unpairedInputs')]
    public function test_an_input_that_is_not_paired_is_accepted_without_items(string $status, ?array $reference, ?array $candidate): void
    {
        $unpaired = static function (array $input) use ($status, $reference, $candidate): array {
            $input['status'] = $status;
            $input['reference'] = $reference;
            $input['candidate'] = $candidate;
            $input['items'] = [];

            return $input;
        };
        self::assertValid(self::withInput($unpaired));

        self::assertInvalid(self::withInput(static function (array $input) use ($unpaired): array {
            $input = $unpaired($input);
            $input['items'] = [['subject' => 'execution', 'reference' => 'failed', 'candidate' => 'succeeded', 'change' => 'fixed']];

            return $input;
        }));
    }

    public static function inconsistentInputs(): iterable
    {
        yield 'paired without a candidate attempt' => ['paired', self::side('run-20261107T120000Z'), null];
        yield 'paired with an unavailable input' => ['paired', self::side('run-20261107T120000Z', 'unavailable'), self::side('run-20261107T130000Z')];
        yield 'unavailable while available in both runs' => ['unavailable', self::side('run-20261107T120000Z'), self::side('run-20261107T130000Z')];
        yield 'only in the reference run with a candidate attempt' => ['only_in_reference', self::side('run-20261107T120000Z'), self::side('run-20261107T130000Z')];
        yield 'input changed with an unavailable input' => ['input_changed', self::side('run-20261107T120000Z'), self::side('run-20261107T130000Z', 'unavailable')];
    }

    #[DataProvider('inconsistentInputs')]
    public function test_an_input_status_that_contradicts_its_attempts_is_rejected(string $status, ?array $reference, ?array $candidate): void
    {
        self::assertInvalid(self::withInput(static function (array $input) use ($status, $reference, $candidate): array {
            $input['status'] = $status;
            $input['reference'] = $reference;
            $input['candidate'] = $candidate;
            if ($status !== 'paired') {
                $input['items'] = [];
            }

            return $input;
        }));
    }

    public function test_a_capability_change_is_never_classified_as_fixed_or_introduced(): void
    {
        self::assertInvalid(self::withInput(static function (array $input): array {
            $input['items'][0] = ['subject' => 'capability', 'reference' => 'supported', 'candidate' => 'expected_rejection', 'change' => 'fixed'];

            return $input;
        }));
    }

    public function test_a_verdict_names_its_analyzer_and_other_items_do_not(): void
    {
        self::assertInvalid(self::withInput(static function (array $input): array {
            unset($input['items'][2]['analyzer_id']);

            return $input;
        }));
        self::assertInvalid(self::withInput(static function (array $input): array {
            $input['items'][1]['analyzer_id'] = 'qpdf';

            return $input;
        }));
    }

    public function test_an_item_value_outside_its_vocabulary_is_rejected(): void
    {
        self::assertInvalid(self::withInput(static function (array $input): array {
            $input['items'][1]['candidate'] = 'valid';

            return $input;
        }));
        self::assertInvalid(self::withInput(static function (array $input): array {
            $input['items'][2]['candidate'] = 'succeeded';

            return $input;
        }));
    }

    public function test_an_analyzer_difference_names_the_analyzer(): void
    {
        self::assertInvalid(self::mutate(function (array $comparison): array {
            unset($comparison['differences'][1]['analyzer_id']);

            return $comparison;
        }));
        self::assertInvalid(self::mutate(function (array $comparison): array {
            $comparison['differences'][0]['analyzer_id'] = 'qpdf';

            return $comparison;
        }));
    }
}
