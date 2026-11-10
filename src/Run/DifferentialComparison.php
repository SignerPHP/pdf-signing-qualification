<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class DifferentialComparison
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/differential.schema.json';

    public const array CLASSIFICATIONS = [
        'agreement',
        'shared_failure',
        'capability_mismatch',
        'expected_rejection',
        'reproducible_implementation_defect',
        'validator_disagreement',
        'unresolved_finding',
    ];

    private const array SIDES = ['first', 'second'];

    private const array INPUT_STATUSES = ['paired', 'unavailable', 'input_changed'];

    private const int RUNS_PER_SIDE = 2;

    /**
     * @param  list<RunRecords>  $first
     * @param  list<RunRecords>  $second
     */
    public static function compare(array $first, array $second, string $selection): array
    {
        self::assertComparable($first, $second);
        $reference = $first[0];

        $properties = [];
        foreach ([...$first, ...$second] as $records) {
            foreach ($records->observations as $observation) {
                $properties[$observation['analyzer']['id']] = $observation['property'];
            }
        }
        $baselineAnalyzerIds = [];
        foreach ([...$first, ...$second] as $records) {
            foreach ($records->baselines ?? [] as $baseline) {
                $baselineAnalyzerIds[$baseline['analyzer']['id']] = true;
            }
        }
        $analyzers = [];
        foreach ($reference->run['analyzers'] as $analyzer) {
            if (! isset($properties[$analyzer['id']])) {
                throw new RuntimeException("The runs have no observation from {$analyzer['id']}, so the property it judges is unknown");
            }
            $analyzers[] = $analyzer + ['property' => $properties[$analyzer['id']], 'baseline' => isset($baselineAnalyzerIds[$analyzer['id']])];
        }

        $context = [];
        foreach (['first' => $first, 'second' => $second] as $side => $runs) {
            foreach ($runs as $records) {
                $context[$side][] = self::runContext($records);
            }
        }

        $inputs = [];
        foreach ($reference->attempts as $referenceAttempt) {
            $inputs[] = self::input($referenceAttempt['input']['source_id'], $context, $analyzers);
        }
        if (! in_array('paired', array_column($inputs, 'status'), true)) {
            throw new RuntimeException('The runs have no input available in every run with the same content, so there is nothing to compare');
        }

        $inputTotals = array_fill_keys(self::INPUT_STATUSES, 0);
        $itemTotals = array_fill_keys(self::CLASSIFICATIONS, 0);
        $inputsByClassification = array_fill_keys(self::CLASSIFICATIONS, 0);
        foreach ($inputs as $input) {
            $inputTotals[$input['status']]++;
            $classifications = array_column($input['items'], 'classification');
            foreach ($classifications as $classification) {
                $itemTotals[$classification]++;
            }
            if ($classifications === []) {
                continue;
            }
            if (array_unique($classifications) === ['agreement']) {
                $inputsByClassification['agreement']++;
            }
            foreach (array_diff(array_unique($classifications), ['agreement']) as $classification) {
                $inputsByClassification[$classification]++;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'scenario_id' => $reference->run['scenario_id'],
            'selection' => $selection,
            'analyzers' => $analyzers,
            'first' => self::side($first),
            'second' => self::side($second),
            'totals' => ['inputs' => $inputTotals, 'items' => $itemTotals, 'inputs_by_classification' => $inputsByClassification],
            'inputs' => $inputs,
        ];
    }

    /**
     * @param  list<RunRecords>  $first
     * @param  list<RunRecords>  $second
     */
    private static function assertComparable(array $first, array $second): void
    {
        if (count($first) !== self::RUNS_PER_SIDE || count($second) !== self::RUNS_PER_SIDE) {
            throw new RuntimeException('Each implementation needs exactly '.self::RUNS_PER_SIDE.' runs, so that a difference can be reproduced');
        }
        $runs = [...$first, ...$second];
        $runIds = array_map(static fn (RunRecords $records): string => $records->run['id'], $runs);
        if (count(array_unique($runIds)) !== count($runIds)) {
            throw new RuntimeException('The same run is given more than once: '.implode(', ', array_unique(array_diff_assoc($runIds, array_unique($runIds)))));
        }
        foreach (['first' => $first, 'second' => $second] as $runsOfSide) {
            if ($runsOfSide[0]->run['implementation'] !== $runsOfSide[1]->run['implementation']) {
                throw new RuntimeException("The runs {$runsOfSide[0]->run['id']} and {$runsOfSide[1]->run['id']} are not of the same implementation at the same revision, so they do not repeat each other");
            }
        }
        if ($first[0]->run['implementation']['id'] === $second[0]->run['implementation']['id']) {
            throw new RuntimeException("Both implementations are {$first[0]->run['implementation']['id']}; compare two revisions of one implementation with compare-runs");
        }

        $reference = $first[0];
        $referenceSourceIds = self::sourceIds($reference);
        foreach ($runs as $records) {
            if ($records->baselines === null) {
                throw new RuntimeException("The run {$records->run['id']} has no baselines.json, so a failure inherited from an input cannot be told apart");
            }
            $settings = [
                'scenario' => [$reference->run['scenario_id'], $records->run['scenario_id']],
                'source configuration' => [$reference->run['source_configuration'], $records->run['source_configuration']],
                'expected rejections' => [$reference->run['expected_rejections'] ?? null, $records->run['expected_rejections'] ?? null],
                'analyzers' => [$reference->run['analyzers'], $records->run['analyzers']],
                'analyzer policies' => [
                    [RunComparison::policies($reference), self::baselinePolicies($reference)],
                    [RunComparison::policies($records), self::baselinePolicies($records)],
                ],
                'resource limits' => [$reference->run['environment']['resource_limits'] ?? null, $records->run['environment']['resource_limits'] ?? null],
                'inputs' => [$referenceSourceIds, self::sourceIds($records)],
            ];
            foreach ($settings as $setting => [$referenceValue, $value]) {
                if ($referenceValue !== $value) {
                    throw new RuntimeException("The runs {$reference->run['id']} and {$records->run['id']} differ in their {$setting}, so a difference between them could not be attributed to the implementations");
                }
            }
        }
    }

    /**
     * @return array<string, list<array>>
     */
    private static function baselinePolicies(RunRecords $records): array
    {
        $policies = [];
        foreach ($records->baselines ?? [] as $baseline) {
            $analyzerId = $baseline['analyzer']['id'];
            if (! in_array($baseline['policy'], $policies[$analyzerId] ?? [], true)) {
                $policies[$analyzerId][] = $baseline['policy'];
            }
        }
        ksort($policies);
        foreach ($policies as $analyzerId => $analyzerPolicies) {
            usort($analyzerPolicies, static fn (array $first, array $second): int => strcmp(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR)));
            $policies[$analyzerId] = $analyzerPolicies;
        }

        return $policies;
    }

    /**
     * @return list<string>
     */
    private static function sourceIds(RunRecords $records): array
    {
        $sourceIds = array_map(static fn (array $attempt): string => $attempt['input']['source_id'], $records->attempts);
        sort($sourceIds);

        return $sourceIds;
    }

    /**
     * @param  list<RunRecords>  $runs
     */
    private static function side(array $runs): array
    {
        return [
            'implementation' => $runs[0]->run['implementation'],
            'runs' => array_map(static function (RunRecords $records): array {
                $run = [
                    'run_id' => $records->run['id'],
                    'container_image' => $records->run['environment']['container_image'],
                ];
                if (isset($records->run['implementation_runtime'])) {
                    $run['implementation_runtime'] = $records->run['implementation_runtime'];
                }

                return $run + [
                    'implementation_compatibility' => $records->run['implementation_compatibility'] ?? null,
                    'coverage' => [...RunReport::countCoverage($records->attempts), 'total' => count($records->attempts)],
                ];
            }, $runs),
        ];
    }

    /**
     * @return array{implementation: string, attempts: array<string, array>, observations: array<string, array<string, array>>, baselines: array<string, array<string, array>>}
     */
    private static function runContext(RunRecords $records): array
    {
        $attempts = [];
        foreach ($records->attempts as $attempt) {
            $attempts[$attempt['input']['source_id']] = $attempt;
        }
        $observations = [];
        foreach ($records->observations as $observation) {
            $observations[$observation['attempt_id']][$observation['analyzer']['id']] = $observation;
        }
        $baselines = [];
        foreach ($records->baselines ?? [] as $baseline) {
            $baselines[$baseline['source_id']][$baseline['analyzer']['id']] = $baseline;
        }

        return ['implementation' => $records->run['implementation']['id'], 'attempts' => $attempts, 'observations' => $observations, 'baselines' => $baselines];
    }

    /**
     * @param  array<string, list<array>>  $context
     * @param  list<array>  $analyzers
     */
    private static function input(string $sourceId, array $context, array $analyzers): array
    {
        $attempts = [];
        foreach (self::SIDES as $side) {
            foreach ($context[$side] as $runContext) {
                $attempts[$side][] = $runContext['attempts'][$sourceId];
            }
        }
        $allAttempts = [...$attempts['first'], ...$attempts['second']];

        $status = 'paired';
        foreach ($allAttempts as $attempt) {
            if ($attempt['input']['status'] === 'unavailable') {
                $status = 'unavailable';
                break;
            }
        }
        if ($status === 'paired') {
            $contents = array_unique(array_map(static fn (array $attempt): string => $attempt['input']['sha256'].':'.$attempt['input']['byte_size'], $allAttempts));
            if (count($contents) > 1) {
                $status = 'input_changed';
            }
        }

        return [
            'source_id' => $sourceId,
            'status' => $status,
            'first_attempt_ids' => array_column($attempts['first'], 'id'),
            'second_attempt_ids' => array_column($attempts['second'], 'id'),
            'items' => $status === 'paired' ? self::items($sourceId, $attempts, $context, $analyzers) : [],
        ];
    }

    /**
     * @param  array<string, list<array>>  $attempts
     * @param  array<string, list<array>>  $context
     * @param  list<array>  $analyzers
     * @return list<array>
     */
    private static function items(string $sourceId, array $attempts, array $context, array $analyzers): array
    {
        $implementations = ['first' => $context['first'][0]['implementation'], 'second' => $context['second'][0]['implementation']];
        $allAttempts = [...$attempts['first'], ...$attempts['second']];
        $capabilities = array_column($allAttempts, 'capability');
        $situation = [
            'implementations' => $implementations,
            'capability_differs' => count(array_unique($capabilities)) > 1,
            'expected_rejection' => in_array('expected_rejection', $capabilities, true),
        ];

        $inputBaselines = [];
        foreach (self::SIDES as $side) {
            foreach ($context[$side] as $runContext) {
                foreach ($runContext['baselines'][$sourceId] ?? [] as $baseline) {
                    $inputBaselines[] = $baseline;
                }
            }
        }
        $baselineAnalyzerCount = count(array_filter($analyzers, static fn (array $analyzer): bool => $analyzer['baseline']));
        $inputIsClean = count($inputBaselines) === $baselineAnalyzerCount * self::RUNS_PER_SIDE * count(self::SIDES)
            && array_filter($inputBaselines, static fn (array $baseline): bool => ! in_array($baseline['verdict'], ['valid', 'not_applicable'], true)) === [];
        $notCleanBaselines = array_values(array_unique(array_map(
            static fn (array $baseline): string => "`{$baseline['analyzer']['id']}` `{$baseline['verdict']}`",
            array_filter($inputBaselines, static fn (array $baseline): bool => ! in_array($baseline['verdict'], ['valid', 'not_applicable'], true)),
        )));

        $items = [
            self::classify(
                ['subject' => 'capability'],
                self::valuesBySide($attempts, static fn (array $attempt): string => $attempt['capability']),
                self::valuesBySide($attempts, static fn (array $attempt): ?string => null),
                $situation,
                [],
                [],
                null,
            ),
            self::classify(
                ['subject' => 'execution'],
                self::valuesBySide($attempts, static fn (array $attempt): string => $attempt['execution']['outcome']),
                self::valuesBySide($attempts, self::executionState(...)),
                $situation,
                [],
                array_column($inputBaselines, 'id'),
                $inputIsClean ? null : ($notCleanBaselines === [] ? 'The input has no baseline from every analyzer in every run' : 'The input itself is '.implode(', ', $notCleanBaselines).' before signing'),
            ),
        ];

        foreach ($allAttempts as $attempt) {
            if ($attempt['execution']['outcome'] !== 'succeeded') {
                return $items;
            }
        }

        $observations = [];
        foreach (self::SIDES as $side) {
            foreach ($context[$side] as $index => $runContext) {
                $observations[$side][] = $runContext['observations'][$attempts[$side][$index]['id']];
            }
        }
        foreach ($analyzers as $analyzer) {
            $analyzerId = $analyzer['id'];
            $verdicts = [];
            $states = [];
            $observationIds = [];
            $disagreement = false;
            foreach (self::SIDES as $side) {
                foreach ($observations[$side] as $attemptObservations) {
                    $verdict = $attemptObservations[$analyzerId]['verdict'];
                    $verdicts[$side][] = $verdict;
                    $states[$side][] = self::verdictState($verdict);
                    $observationIds[] = $attemptObservations[$analyzerId]['id'];
                    $propertyStates = [];
                    foreach ($analyzers as $other) {
                        if ($other['property'] === $analyzer['property']) {
                            $propertyStates[] = self::verdictState($attemptObservations[$other['id']]['verdict']);
                        }
                    }
                    if (in_array('failure', $propertyStates, true) && in_array('pass', $propertyStates, true)) {
                        $disagreement = true;
                    }
                }
            }

            $baselineIds = [];
            $inheritedFrom = null;
            if ($analyzer['baseline']) {
                $baselineVerdicts = [];
                foreach (self::SIDES as $side) {
                    foreach ($context[$side] as $runContext) {
                        $baseline = $runContext['baselines'][$sourceId][$analyzerId] ?? null;
                        if ($baseline !== null) {
                            $baselineIds[] = $baseline['id'];
                            $baselineVerdicts[] = $baseline['verdict'];
                        }
                    }
                }
                $uniqueVerdicts = array_values(array_unique($baselineVerdicts));
                if (count($baselineVerdicts) !== self::RUNS_PER_SIDE * count(self::SIDES)) {
                    $inheritedFrom = "The input has no baseline from `{$analyzerId}` in every run";
                } elseif (count($uniqueVerdicts) > 1) {
                    $inheritedFrom = "The baseline of `{$analyzerId}` on the input differs between runs: ".implode(', ', array_map(static fn (string $verdict): string => "`{$verdict}`", $uniqueVerdicts));
                } elseif (Summary::isOutputFailure($uniqueVerdicts[0])) {
                    $inheritedFrom = "The input itself is `{$uniqueVerdicts[0]}` for `{$analyzerId}` before signing";
                }
            }

            $items[] = self::classify(
                ['subject' => 'verdict', 'analyzer_id' => $analyzerId],
                $verdicts,
                $states,
                $situation + ['disagreement' => $disagreement ? $analyzer['property'] : null],
                $observationIds,
                $baselineIds,
                $inheritedFrom,
            );
        }

        return $items;
    }

    /**
     * @param  array<string, list<array>>  $attempts
     * @return array<string, list<mixed>>
     */
    private static function valuesBySide(array $attempts, callable $value): array
    {
        $values = [];
        foreach (self::SIDES as $side) {
            $values[$side] = array_map($value, $attempts[$side]);
        }

        return $values;
    }

    /**
     * @param  array{subject: string, analyzer_id?: string}  $item
     * @param  array<string, list<string>>  $values
     * @param  array<string, list<?string>>  $states
     * @param  list<string>  $observationIds
     * @param  list<string>  $baselineIds
     */
    private static function classify(array $item, array $values, array $states, array $situation, array $observationIds, array $baselineIds, ?string $inheritedFrom): array
    {
        $implementations = $situation['implementations'];
        $labels = [];
        foreach (self::SIDES as $side) {
            foreach ($values[$side] as $index => $value) {
                $labels[$side][] = self::label($item['subject'], $value, $states[$side][$index], $situation['expected_rejection']);
            }
        }
        $allLabels = [...$labels['first'], ...$labels['second']];
        $defectiveSide = null;
        $unstable = array_values(array_filter(self::SIDES, static fn (string $side): bool => $labels[$side][0] !== $labels[$side][1]));
        $failingSide = null;
        foreach (self::SIDES as $side) {
            $otherSide = $side === 'first' ? 'second' : 'first';
            if ($states[$side] === ['failure', 'failure'] && $states[$otherSide] === [null, null] && $values[$otherSide] === ['failed', 'failed']) {
                $failingSide = $side;
            }
        }

        if (count(array_unique($allLabels)) === 1) {
            $classification = $states['first'][0] === 'failure' ? 'shared_failure' : 'agreement';
            $reason = "Every run of both implementations gave {$allLabels[0]}";
        } elseif ($situation['capability_differs']) {
            $classification = 'capability_mismatch';
            $reason = 'The attempts on this input do not have the same capability, so the implementations were not expected to behave alike';
        } elseif ($unstable !== []) {
            $classification = 'unresolved_finding';
            $reason = 'Not reproduced: '.implode('; ', array_map(static fn (string $side): string => "`{$implementations[$side]}` gave {$labels[$side][0]} in its first run and {$labels[$side][1]} in its second", $unstable));
        } elseif ($situation['expected_rejection'] && $item['subject'] === 'execution' && $failingSide !== null) {
            $passingSide = $failingSide === 'first' ? 'second' : 'first';
            $classification = 'reproducible_implementation_defect';
            $defectiveSide = $failingSide;
            $reason = "The input is declared as an expected rejection: `{$implementations[$passingSide]}` rejected it in both runs and `{$implementations[$failingSide]}` gave {$labels[$failingSide][0]} in both";
        } elseif ($situation['expected_rejection']) {
            $classification = 'expected_rejection';
            $reason = "The input is declared as an expected rejection under this scenario: `{$implementations['first']}` gave {$labels['first'][0]} and `{$implementations['second']}` gave {$labels['second'][0]}";
        } elseif (($situation['disagreement'] ?? null) !== null) {
            $classification = 'validator_disagreement';
            $reason = "The analyzers of `{$situation['disagreement']}` disagree on at least one of the outputs, so the difference is not settled by vote";
        } elseif (in_array($states['first'][0], ['failure', 'pass'], true) && in_array($states['second'][0], ['failure', 'pass'], true) && $states['first'][0] !== $states['second'][0]) {
            $defectiveSide = $states['first'][0] === 'failure' ? 'first' : 'second';
            $passingSide = $defectiveSide === 'first' ? 'second' : 'first';
            $observed = "`{$implementations[$defectiveSide]}` gave {$labels[$defectiveSide][0]} in both runs and `{$implementations[$passingSide]}` gave {$labels[$passingSide][0]} in both";
            if ($inheritedFrom !== null) {
                $classification = 'unresolved_finding';
                $reason = "{$observed}, but the failure may come from the input: {$inheritedFrom}";
                $defectiveSide = null;
            } else {
                $classification = 'reproducible_implementation_defect';
                $reason = $observed;
            }
        } elseif ($states['first'][0] === 'failure' && $states['second'][0] === 'failure') {
            $classification = 'unresolved_finding';
            $reason = "Both implementations fail this item, differently: `{$implementations['first']}` gave {$labels['first'][0]} and `{$implementations['second']}` gave {$labels['second'][0]}";
        } else {
            $classification = 'unresolved_finding';
            $reason = "The values differ and the evidence does not attribute the difference: `{$implementations['first']}` gave {$labels['first'][0]} and `{$implementations['second']}` gave {$labels['second'][0]}";
        }

        $classified = $item + ['first' => $values['first'], 'second' => $values['second'], 'classification' => $classification];
        if ($defectiveSide !== null) {
            $classified['defective_side'] = $defectiveSide;
        }

        return $classified + ['reason' => $reason, 'observation_ids' => $observationIds, 'baseline_ids' => $baselineIds];
    }

    private static function label(string $subject, string $value, ?string $state, bool $expectedRejection): string
    {
        if ($subject !== 'execution' || ! $expectedRejection || $value !== 'failed') {
            return "`{$value}`";
        }

        return $state === 'failure' ? '`failed` without rejecting the input' : '`failed`, rejecting the input as expected';
    }

    private static function executionState(array $attempt): ?string
    {
        if (Summary::isExecutionFailure($attempt)) {
            return 'failure';
        }

        return $attempt['execution']['outcome'] === 'succeeded' ? 'pass' : null;
    }

    private static function verdictState(string $verdict): ?string
    {
        if (Summary::isOutputFailure($verdict)) {
            return 'failure';
        }

        return $verdict === 'valid' ? 'pass' : null;
    }
}
