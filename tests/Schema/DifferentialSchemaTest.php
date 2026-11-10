<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

final class DifferentialSchemaTest extends SchemaTestCase
{
    private const string SOURCE_ID = 'qpdf:qpdf/qtest/qpdf/good17.pdf';

    protected static function schemaPath(): string
    {
        return 'v1/differential.schema.json';
    }

    private static function attemptIds(string $implementationId, string $hour): array
    {
        return ["run-20261107T{$hour}0000Z/basic-invisible-sha256/{$implementationId}/".self::SOURCE_ID, "run-20261107T{$hour}3000Z/basic-invisible-sha256/{$implementationId}/".self::SOURCE_ID];
    }

    private static function side(array $implementation, string $hour): array
    {
        $coverage = ['unavailable' => 0, 'unsupported' => 0, 'rejected_as_expected' => 0, 'not_signed' => 0, 'signed' => 1, 'total' => 1];

        return [
            'implementation' => $implementation,
            'runs' => [
                ['run_id' => "run-20261107T{$hour}0000Z", 'container_image' => ['status' => 'available', 'id' => 'sha256:'.str_repeat('c', 64)], 'implementation_compatibility' => ['status' => 'compatible'], 'coverage' => $coverage],
                ['run_id' => "run-20261107T{$hour}3000Z", 'container_image' => ['status' => 'unavailable', 'reason' => 'not given'], 'implementation_compatibility' => null, 'coverage' => $coverage],
            ],
        ];
    }

    protected static function validDocument(): array
    {
        $classifications = ['agreement' => 2, 'shared_failure' => 0, 'capability_mismatch' => 0, 'expected_rejection' => 0, 'reproducible_implementation_defect' => 1, 'validator_disagreement' => 0, 'unresolved_finding' => 0];
        $observationIds = array_map(static fn (string $attemptId): string => "{$attemptId}/qpdf", [...self::attemptIds('pdf-signer', '12'), ...self::attemptIds('jsignpdf', '13')]);

        return [
            'schema' => self::schemaId(),
            'scenario_id' => 'basic-invisible-sha256',
            'selection' => 'stratified',
            'analyzers' => [['id' => 'qpdf', 'revision' => ['kind' => 'version', 'value' => '11.3.0'], 'property' => 'structure', 'baseline' => true]],
            'first' => self::side(['id' => 'pdf-signer', 'revision' => ['kind' => 'commit', 'value' => str_repeat('a', 40)]], '12'),
            'second' => self::side(['id' => 'jsignpdf', 'revision' => ['kind' => 'version', 'value' => '3.2.1']], '13'),
            'totals' => [
                'inputs' => ['paired' => 1, 'unavailable' => 0, 'input_changed' => 0],
                'items' => $classifications,
                'inputs_by_classification' => ['reproducible_implementation_defect' => 1, 'agreement' => 0] + $classifications,
            ],
            'inputs' => [[
                'source_id' => self::SOURCE_ID,
                'status' => 'paired',
                'first_attempt_ids' => self::attemptIds('pdf-signer', '12'),
                'second_attempt_ids' => self::attemptIds('jsignpdf', '13'),
                'items' => [
                    ['subject' => 'capability', 'first' => ['supported', 'supported'], 'second' => ['supported', 'supported'], 'classification' => 'agreement', 'reason' => 'Every run gave supported', 'observation_ids' => [], 'baseline_ids' => []],
                    ['subject' => 'execution', 'first' => ['succeeded', 'succeeded'], 'second' => ['succeeded', 'succeeded'], 'classification' => 'agreement', 'reason' => 'Every run gave succeeded', 'observation_ids' => [], 'baseline_ids' => []],
                    ['subject' => 'verdict', 'analyzer_id' => 'qpdf', 'first' => ['invalid', 'invalid'], 'second' => ['valid', 'valid'], 'classification' => 'reproducible_implementation_defect', 'defective_side' => 'first', 'reason' => 'pdf-signer gave invalid in both runs', 'observation_ids' => $observationIds, 'baseline_ids' => []],
                ],
            ]],
        ];
    }

    private static function withItem(int $index, callable $mutation): array
    {
        return self::mutate(static function (array $comparison) use ($index, $mutation): array {
            $comparison['inputs'][0]['items'][$index] = $mutation($comparison['inputs'][0]['items'][$index]);

            return $comparison;
        });
    }

    public function test_the_valid_document_is_accepted(): void
    {
        self::assertValid(self::validDocument());
    }

    public function test_a_defect_names_its_defective_side_and_nothing_else_does(): void
    {
        self::assertInvalid(self::withItem(2, static function (array $item): array {
            unset($item['defective_side']);

            return $item;
        }));
        self::assertInvalid(self::withItem(0, static fn (array $item): array => $item + ['defective_side' => 'second']));
    }

    public function test_a_verdict_cites_the_observation_of_each_of_the_four_runs(): void
    {
        self::assertInvalid(self::withItem(2, static function (array $item): array {
            array_pop($item['observation_ids']);

            return $item;
        }));
        self::assertInvalid(self::withItem(1, static fn (array $item): array => ['observation_ids' => ['run-20261107T120000Z/x/qpdf']] + $item));
    }

    public function test_only_a_verdict_names_an_analyzer(): void
    {
        self::assertInvalid(self::withItem(2, static function (array $item): array {
            unset($item['analyzer_id']);

            return $item;
        }));
        self::assertInvalid(self::withItem(0, static fn (array $item): array => $item + ['analyzer_id' => 'qpdf']));
    }

    public function test_each_side_has_exactly_two_runs_and_values(): void
    {
        self::assertInvalid(self::mutate(static function (array $comparison): array {
            $comparison['second']['runs'][] = $comparison['second']['runs'][0];

            return $comparison;
        }));
        self::assertInvalid(self::withItem(0, static fn (array $item): array => ['first' => ['supported']] + $item));
    }

    public function test_an_input_that_is_not_paired_has_no_items(): void
    {
        self::assertInvalid(self::mutate(static function (array $comparison): array {
            $comparison['inputs'][0]['status'] = 'unavailable';

            return $comparison;
        }));
        self::assertValid(self::mutate(static function (array $comparison): array {
            $comparison['inputs'][0]['status'] = 'input_changed';
            $comparison['inputs'][0]['items'] = [];

            return $comparison;
        }));
    }

    public function test_an_unknown_classification_is_rejected(): void
    {
        self::assertInvalid(self::withItem(2, static fn (array $item): array => ['classification' => 'majority'] + $item));
    }
}
