<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class RunComparison
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/comparison.schema.json';

    private const array INPUT_STATUSES = ['paired', 'only_in_reference', 'only_in_candidate', 'unavailable', 'input_changed'];

    private const array CHANGES = ['introduced', 'fixed', 'persistent', 'changed', 'unchanged'];

    public static function compare(RunRecords $reference, RunRecords $candidate): array
    {
        $sharedAnalyzerIds = array_values(array_intersect(array_column($reference->run['analyzers'], 'id'), array_column($candidate->run['analyzers'], 'id')));
        $referenceAttempts = self::attemptsByKey($reference);
        $candidateAttempts = self::attemptsByKey($candidate);
        $referenceVerdicts = self::verdictsByAttempt($reference);
        $candidateVerdicts = self::verdictsByAttempt($candidate);

        $inputs = [];
        foreach ($referenceAttempts as $key => $referenceAttempt) {
            $inputs[] = self::input($referenceAttempt, $candidateAttempts[$key] ?? null, $sharedAnalyzerIds, $referenceVerdicts, $candidateVerdicts);
        }
        foreach (array_diff_key($candidateAttempts, $referenceAttempts) as $candidateAttempt) {
            $inputs[] = self::input(null, $candidateAttempt, $sharedAnalyzerIds, $referenceVerdicts, $candidateVerdicts);
        }

        $inputTotals = array_fill_keys(self::INPUT_STATUSES, 0);
        $changeTotals = array_fill_keys(self::CHANGES, 0);
        foreach ($inputs as $input) {
            $inputTotals[$input['status']]++;
            foreach ($input['items'] as $item) {
                $changeTotals[$item['change']]++;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'reference' => self::runDescription($reference->run),
            'candidate' => self::runDescription($candidate->run),
            'differences' => self::differences($reference, $candidate, $sharedAnalyzerIds),
            'totals' => ['inputs' => $inputTotals, 'changes' => $changeTotals],
            'inputs' => $inputs,
        ];
    }

    public static function hasNewFailures(array $comparison): bool
    {
        return $comparison['totals']['changes']['introduced'] > 0;
    }

    private static function runDescription(array $run): array
    {
        return [
            'run_id' => $run['id'],
            'scenario_id' => $run['scenario_id'],
            'implementation' => $run['implementation'],
            'analyzers' => $run['analyzers'],
        ];
    }

    /**
     * @return array<string, array>
     */
    private static function attemptsByKey(RunRecords $records): array
    {
        $attempts = [];
        foreach ($records->attempts as $attempt) {
            $attempts[$attempt['scenario_id']."\n".$attempt['input']['source_id']] = $attempt;
        }

        return $attempts;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function verdictsByAttempt(RunRecords $records): array
    {
        $verdicts = [];
        foreach ($records->observations as $observation) {
            $verdicts[$observation['attempt_id']][$observation['analyzer']['id']] = $observation['verdict'];
        }

        return $verdicts;
    }

    /**
     * @param  list<string>  $sharedAnalyzerIds
     * @param  array<string, array<string, string>>  $referenceVerdicts
     * @param  array<string, array<string, string>>  $candidateVerdicts
     */
    private static function input(?array $referenceAttempt, ?array $candidateAttempt, array $sharedAnalyzerIds, array $referenceVerdicts, array $candidateVerdicts): array
    {
        $attempt = $referenceAttempt ?? $candidateAttempt;
        $status = self::inputStatus($referenceAttempt, $candidateAttempt);

        $items = [];
        if ($status === 'paired' && $referenceAttempt !== null && $candidateAttempt !== null) {
            $items[] = self::item('capability', null, $referenceAttempt['capability'], $candidateAttempt['capability'], null, null);
            $items[] = self::item('execution', null, $referenceAttempt['execution']['outcome'], $candidateAttempt['execution']['outcome'], self::executionState($referenceAttempt), self::executionState($candidateAttempt));
            foreach ($sharedAnalyzerIds as $analyzerId) {
                $referenceVerdict = $referenceVerdicts[$referenceAttempt['id']][$analyzerId];
                $candidateVerdict = $candidateVerdicts[$candidateAttempt['id']][$analyzerId];
                $items[] = self::item('verdict', $analyzerId, $referenceVerdict, $candidateVerdict, self::verdictState($referenceVerdict), self::verdictState($candidateVerdict));
            }
        }

        return [
            'source_id' => $attempt['input']['source_id'],
            'scenario_id' => $attempt['scenario_id'],
            'status' => $status,
            'reference' => $referenceAttempt === null ? null : ['attempt_id' => $referenceAttempt['id'], 'input' => $referenceAttempt['input']],
            'candidate' => $candidateAttempt === null ? null : ['attempt_id' => $candidateAttempt['id'], 'input' => $candidateAttempt['input']],
            'items' => $items,
        ];
    }

    private static function inputStatus(?array $referenceAttempt, ?array $candidateAttempt): string
    {
        if ($candidateAttempt === null) {
            return 'only_in_reference';
        }
        if ($referenceAttempt === null) {
            return 'only_in_candidate';
        }
        $referenceInput = $referenceAttempt['input'];
        $candidateInput = $candidateAttempt['input'];
        if ($referenceInput['status'] === 'unavailable' || $candidateInput['status'] === 'unavailable') {
            return 'unavailable';
        }

        return [$referenceInput['sha256'], $referenceInput['byte_size']] === [$candidateInput['sha256'], $candidateInput['byte_size']] ? 'paired' : 'input_changed';
    }

    private static function item(string $subject, ?string $analyzerId, string $referenceValue, string $candidateValue, ?string $referenceState, ?string $candidateState): array
    {
        $change = match (true) {
            $referenceState === 'failure' && $candidateState === 'failure' => 'persistent',
            $candidateState === 'failure' => 'introduced',
            $referenceState === 'failure' && $candidateState === 'pass' => 'fixed',
            $referenceState === 'failure' => 'changed',
            $referenceValue === $candidateValue => 'unchanged',
            default => 'changed',
        };

        $item = ['subject' => $subject];
        if ($analyzerId !== null) {
            $item['analyzer_id'] = $analyzerId;
        }

        return $item + ['reference' => $referenceValue, 'candidate' => $candidateValue, 'change' => $change];
    }

    private static function executionState(array $attempt): ?string
    {
        $outcome = $attempt['execution']['outcome'];
        if ($attempt['capability'] === 'expected_rejection') {
            $rejected = $outcome === 'failed' && ! in_array($attempt['execution']['exit_code'], [null, 0], true);

            return in_array($outcome, ['failed', 'timed_out'], true) && ! $rejected ? 'failure' : null;
        }

        return match ($outcome) {
            'succeeded' => 'pass',
            'failed', 'timed_out' => 'failure',
            default => null,
        };
    }

    private static function verdictState(string $verdict): ?string
    {
        return match ($verdict) {
            'valid' => 'pass',
            'invalid' => 'failure',
            default => null,
        };
    }

    /**
     * @param  list<string>  $sharedAnalyzerIds
     * @return list<array>
     */
    private static function differences(RunRecords $reference, RunRecords $candidate, array $sharedAnalyzerIds): array
    {
        $differences = [];
        $compare = static function (string $subject, mixed $referenceValue, mixed $candidateValue, ?string $analyzerId = null) use (&$differences): void {
            if ($referenceValue === $candidateValue) {
                return;
            }
            $difference = ['subject' => $subject];
            if ($analyzerId !== null) {
                $difference['analyzer_id'] = $analyzerId;
            }
            $differences[] = $difference + ['reference' => $referenceValue, 'candidate' => $candidateValue];
        };

        $compare('scenario', $reference->run['scenario_id'], $candidate->run['scenario_id']);
        $compare('implementation', $reference->run['implementation'], $candidate->run['implementation']);
        $compare('implementation_runtime', $reference->run['implementation_runtime'] ?? null, $candidate->run['implementation_runtime'] ?? null);

        $referenceRevisions = array_column($reference->run['analyzers'], 'revision', 'id');
        $candidateRevisions = array_column($candidate->run['analyzers'], 'revision', 'id');
        foreach (array_keys($referenceRevisions + $candidateRevisions) as $analyzerId) {
            $compare('analyzer', $referenceRevisions[$analyzerId] ?? null, $candidateRevisions[$analyzerId] ?? null, (string) $analyzerId);
        }
        $referencePolicies = self::policies($reference);
        $candidatePolicies = self::policies($candidate);
        foreach ($sharedAnalyzerIds as $analyzerId) {
            $compare('analyzer_policy', $referencePolicies[$analyzerId] ?? [], $candidatePolicies[$analyzerId] ?? [], $analyzerId);
        }

        $compare('source_configuration', $reference->run['source_configuration'], $candidate->run['source_configuration']);
        $compare('expected_rejections', $reference->run['expected_rejections'] ?? null, $candidate->run['expected_rejections'] ?? null);
        $compare('container_image', $reference->run['environment']['container_image'], $candidate->run['environment']['container_image']);
        $compare('resource_limits', $reference->run['environment']['resource_limits'] ?? null, $candidate->run['environment']['resource_limits'] ?? null);

        return $differences;
    }

    /**
     * @return array<string, list<array>>
     */
    private static function policies(RunRecords $records): array
    {
        $policies = [];
        foreach ($records->observations as $observation) {
            $analyzerId = $observation['analyzer']['id'];
            if (! in_array($observation['policy'], $policies[$analyzerId] ?? [], true)) {
                $policies[$analyzerId][] = $observation['policy'];
            }
        }
        foreach ($policies as $analyzerId => $analyzerPolicies) {
            usort($analyzerPolicies, static fn (array $first, array $second): int => strcmp(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR)));
            $policies[$analyzerId] = $analyzerPolicies;
        }

        return $policies;
    }
}
