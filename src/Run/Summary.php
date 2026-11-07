<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

final class Summary
{
    private const array OUTCOMES = ['succeeded', 'failed', 'timed_out', 'not_run'];

    private const array VERDICTS = ['valid', 'invalid', 'inconclusive', 'not_evaluated', 'not_applicable'];

    private const int CELL_CHARACTERS = 200;

    /**
     * @param  list<array>  $attempts
     * @param  list<array>  $observations
     * @param  list<array>  $baselines
     */
    public static function markdown(array $run, array $attempts, array $observations, array $baselines, ?array $regressions = null): string
    {
        $sourceIds = array_column(array_map(static fn (array $attempt): array => ['id' => $attempt['id'], 'source_id' => $attempt['input']['source_id']], $attempts), 'source_id', 'id');

        $baselineVerdicts = [];
        foreach ($baselines as $baseline) {
            $baselineVerdicts[$baseline['source_id']][$baseline['analyzer']['id']] = $baseline['verdict'];
        }
        $baselineAnalyzerIds = array_unique(array_map(static fn (array $baseline): string => $baseline['analyzer']['id'], $baselines));
        $baselineAnalyzers = array_values(array_filter($run['analyzers'], static fn (array $analyzer): bool => in_array($analyzer['id'], $baselineAnalyzerIds, true)));
        $comparisonAnalyzerIds = array_values(array_diff(array_column($run['analyzers'], 'id'), $baselineAnalyzerIds));

        $lines = [
            "# Run {$run['id']}",
            '',
            "Scenario `{$run['scenario_id']}`, implementation ".self::tool($run['implementation']).', analyzers '.self::sentence(array_values(array_map(self::tool(...), $run['analyzers']))).'.',
            '',
            ...self::compatibility($run),
            ...self::executions($attempts),
            '',
            '## Verdicts',
            '',
            ...self::verdicts($run['analyzers'], $observations),
            '',
            ...self::baseline($baselineAnalyzers, $comparisonAnalyzerIds, $attempts, $observations, $baselines, $baselineVerdicts, $sourceIds),
            ...self::expectedRejections($attempts),
            ...self::regressions($regressions, $attempts, $observations),
            '## Failures',
            '',
            ...self::failures($attempts, $observations, $sourceIds, $baselineVerdicts, $comparisonAnalyzerIds),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private static function compatibility(array $run): array
    {
        $compatibility = $run['implementation_compatibility'] ?? null;
        if ($compatibility === null || $compatibility['status'] !== 'incompatible') {
            return [];
        }

        return ['**The adapter cannot run this revision of `'.$run['implementation']['id'].'`:** '.self::cell($compatibility['reason']).'. Every attempt was still run, so each one records what happened.', ''];
    }

    /**
     * @param  list<array>  $attempts
     * @param  list<array>  $observations
     * @return list<string>
     */
    private static function regressions(?array $regressions, array $attempts, array $observations): array
    {
        if ($regressions === null) {
            return [];
        }
        if ($regressions['cases'] === []) {
            return ['## Regression cases', '', 'No regression case was declared.', ''];
        }

        $attemptsById = array_column([...$attempts, ...$regressions['attempts']], null, 'id');
        $verdicts = [];
        foreach ([...$observations, ...$regressions['observations']] as $observation) {
            $verdicts[$observation['attempt_id']][] = "`{$observation['analyzer']['id']}` `{$observation['verdict']}`";
        }

        $lines = [
            '## Regression cases',
            '',
            'Each case is replayed on its own input and tells whether the condition of its defect is present in the output. They are a failure-enriched selection, so they are not counted in the totals above.',
            '',
            '| Case | Input | Execution | Condition | Evidence | Verdicts |',
            '| ---- | ----- | --------- | --------- | -------- | -------- |',
        ];
        foreach ($regressions['cases'] as $case) {
            $attempt = $case['attempt_id'] === null ? null : $attemptsById[$case['attempt_id']];
            $input = $attempt === null ? 'none' : "`{$attempt['input']['source_id']}`";
            $execution = $attempt === null ? 'none' : "`{$attempt['execution']['outcome']}`";
            $caseVerdicts = $case['attempt_id'] === null ? [] : ($verdicts[$case['attempt_id']] ?? []);
            $lines[] = "| `{$case['case_id']}` | {$input} | {$execution} | `{$case['condition']['status']}` | ".self::cell($case['condition']['evidence']).' | '.($caseVerdicts === [] ? 'none' : implode(', ', $caseVerdicts)).' |';
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  list<array>  $attempts
     * @return list<string>
     */
    private static function executions(array $attempts): array
    {
        $counts = array_fill_keys(self::OUTCOMES, 0);
        $rejectedAsExpected = 0;
        foreach ($attempts as $attempt) {
            if (self::isRejectedAsExpected($attempt)) {
                $rejectedAsExpected++;
            } else {
                $counts[$attempt['execution']['outcome']]++;
            }
        }

        $lines = ['## Executions', '', '| Outcome | Attempts |', '| ------- | -------: |'];
        foreach ($counts as $outcome => $count) {
            $lines[] = "| `{$outcome}` | {$count} |";
        }
        $lines[] = "| Rejected as expected | {$rejectedAsExpected} |";
        $lines[] = '| Total | '.count($attempts).' |';

        return $lines;
    }

    /**
     * @param  list<array>  $attempts
     * @return list<string>
     */
    private static function expectedRejections(array $attempts): array
    {
        $rejections = array_filter($attempts, self::isRejectedAsExpected(...));
        if ($rejections === []) {
            return [];
        }

        $lines = ['## Expected rejections', '', '| Input | Error |', '| ----- | ----- |'];
        foreach ($rejections as $attempt) {
            $lines[] = "| `{$attempt['input']['source_id']}` | ".self::cell((string) ($attempt['execution']['error']['message'] ?? '')).' |';
        }
        $lines[] = '';

        return $lines;
    }

    private static function isExpectedRejection(array $attempt): bool
    {
        return $attempt['capability'] === 'expected_rejection';
    }

    public static function isExecutionFailure(array $attempt): bool
    {
        return $attempt['execution']['outcome'] === 'succeeded'
            ? self::isExpectedRejection($attempt)
            : ! self::isRejectedAsExpected($attempt);
    }

    public static function isRejectedAsExpected(array $attempt): bool
    {
        return self::isExpectedRejection($attempt)
            && $attempt['execution']['outcome'] === 'failed'
            && ! in_array($attempt['execution']['exit_code'], [null, 0], true);
    }

    /**
     * @param  list<array>  $analyzers
     * @param  list<string>  $comparisonAnalyzerIds
     * @param  list<array>  $attempts
     * @param  list<array>  $observations
     * @param  list<array>  $baselines
     * @param  array<string, array<string, string>>  $baselineVerdicts
     * @param  array<string, string>  $sourceIds
     * @return list<string>
     */
    private static function baseline(array $analyzers, array $comparisonAnalyzerIds, array $attempts, array $observations, array $baselines, array $baselineVerdicts, array $sourceIds): array
    {
        $outputVerdicts = [];
        foreach ($observations as $observation) {
            $outputVerdicts[$sourceIds[$observation['attempt_id']]][$observation['analyzer']['id']] = $observation['verdict'];
        }

        $unavailableAttempts = array_values(array_filter($attempts, static fn (array $attempt): bool => $attempt['input']['status'] === 'unavailable'));
        $unavailableLines = [];
        if ($unavailableAttempts !== []) {
            array_push($unavailableLines, 'Inputs without a baseline, because they were unavailable:', '', '| Input | Reason |', '| ----- | ------ |');
            foreach ($unavailableAttempts as $attempt) {
                $unavailableLines[] = "| `{$attempt['input']['source_id']}` | `{$attempt['input']['reason']}` |";
            }
            $unavailableLines[] = '';
        }

        if ($baselines === []) {
            return [
                '## Baseline',
                '',
                count($unavailableAttempts) === count($attempts) ? 'No input was available, so there is no baseline.' : 'No analyzer of this run judges one document, so there is no baseline.',
                '',
                ...$unavailableLines,
            ];
        }

        $analyzerIds = array_column($analyzers, 'id');
        $lines = [
            '## Baseline',
            '',
            "Each analyzer's verdict on the input itself, before signing. It is never combined with the verdict on the output.",
            '',
            ...self::verdicts($analyzers, $baselines),
            '',
        ];
        if ($comparisonAnalyzerIds !== []) {
            $comparisonAnalyzers = self::sentence(array_map(static fn (string $analyzerId): string => "`{$analyzerId}`", $comparisonAnalyzerIds));
            array_push($lines, count($comparisonAnalyzerIds) === 1
                ? "{$comparisonAnalyzers} compares the output with its input, so it has no baseline."
                : "{$comparisonAnalyzers} compare the output with its input, so they have no baseline.", '');
        }
        array_push(
            $lines,
            'Each cell gives the verdict on the input, then the verdict on the output.',
            '',
            '| Input | '.implode(' | ', array_map(static fn (string $analyzerId): string => "`{$analyzerId}`", $analyzerIds)).' |',
            '| ----- | '.implode(' | ', array_map(static fn (string $analyzerId): string => str_repeat('-', strlen($analyzerId) + 2), $analyzerIds)).' |',
        );
        foreach ($attempts as $attempt) {
            if ($attempt['input']['status'] === 'unavailable') {
                continue;
            }
            $sourceId = $attempt['input']['source_id'];
            $cells = array_map(static fn (string $analyzerId): string => "`{$baselineVerdicts[$sourceId][$analyzerId]}` → `{$outputVerdicts[$sourceId][$analyzerId]}`", $analyzerIds);
            $lines[] = "| `{$sourceId}` | ".implode(' | ', $cells).' |';
        }
        $lines[] = '';

        return [...$lines, ...$unavailableLines];
    }

    public static function isOutputFailure(string $verdict): bool
    {
        return in_array($verdict, ['invalid', 'inconclusive'], true);
    }

    /**
     * @param  list<array>  $analyzers
     * @param  list<array>  $records
     * @return list<string>
     */
    private static function verdicts(array $analyzers, array $records): array
    {
        $counts = array_fill_keys(array_column($analyzers, 'id'), array_fill_keys(self::VERDICTS, 0));
        foreach ($records as $record) {
            $counts[$record['analyzer']['id']][$record['verdict']]++;
        }

        $lines = [
            '| Analyzer | '.implode(' | ', array_map(static fn (string $verdict): string => "`{$verdict}`", self::VERDICTS)).' |',
            '| -------- | '.implode(' | ', array_map(static fn (string $verdict): string => str_repeat('-', strlen($verdict) + 1).':', self::VERDICTS)).' |',
        ];
        foreach ($counts as $analyzerId => $verdictCounts) {
            $lines[] = "| `{$analyzerId}` | ".implode(' | ', $verdictCounts).' |';
        }

        return $lines;
    }

    /**
     * @param  list<array>  $attempts
     * @param  list<array>  $observations
     * @param  array<string, string>  $sourceIds
     * @param  array<string, array<string, string>>  $baselineVerdicts
     * @param  list<string>  $comparisonAnalyzerIds
     * @return list<string>
     */
    private static function failures(array $attempts, array $observations, array $sourceIds, array $baselineVerdicts, array $comparisonAnalyzerIds): array
    {
        $unsuccessfulAttempts = array_filter($attempts, static fn (array $attempt): bool => $attempt['execution']['outcome'] !== 'succeeded' && ! self::isRejectedAsExpected($attempt));
        $unexpectedSuccesses = array_filter($attempts, static fn (array $attempt): bool => self::isExpectedRejection($attempt) && $attempt['execution']['outcome'] === 'succeeded');
        $judgedOutputs = array_filter($observations, static fn (array $observation): bool => self::isOutputFailure($observation['verdict']));
        if ($unsuccessfulAttempts === [] && $unexpectedSuccesses === [] && $judgedOutputs === []) {
            return [array_filter($attempts, self::isRejectedAsExpected(...)) === []
                ? 'Every attempt succeeded and every output was judged valid.'
                : 'No other attempt failed and no output was judged invalid or inconclusive.'];
        }

        $lines = [];
        if ($unsuccessfulAttempts !== []) {
            array_push($lines, '### Executions that did not succeed', '', '| Input | Capability | Outcome | Error |', '| ----- | ---------- | ------- | ----- |');
            foreach ($unsuccessfulAttempts as $attempt) {
                $lines[] = "| `{$attempt['input']['source_id']}` | `{$attempt['capability']}` | `{$attempt['execution']['outcome']}` | ".self::cell((string) ($attempt['execution']['error']['message'] ?? '')).' |';
            }
            $lines[] = '';
        }
        if ($unexpectedSuccesses !== []) {
            array_push($lines, '### Expected rejections that succeeded', '', '| Input |', '| ----- |');
            foreach ($unexpectedSuccesses as $attempt) {
                $lines[] = "| `{$attempt['input']['source_id']}` |";
            }
            $lines[] = '';
        }
        if ($judgedOutputs !== []) {
            array_push($lines, '### Outputs not judged valid', '', '| Input | Analyzer | Baseline | Verdict | Summary |', '| ----- | -------- | -------- | ------- | ------- |');
            foreach ($judgedOutputs as $observation) {
                $sourceId = $sourceIds[$observation['attempt_id']];
                $analyzerId = $observation['analyzer']['id'];
                $baseline = in_array($analyzerId, $comparisonAnalyzerIds, true) ? 'none' : "`{$baselineVerdicts[$sourceId][$analyzerId]}`";
                $lines[] = "| `{$sourceId}` | `{$analyzerId}` | {$baseline} | `{$observation['verdict']}` | ".self::cell($observation['evidence']['summary']).' |';
            }
            $lines[] = '';
        }
        array_pop($lines);

        return $lines;
    }

    public static function tool(array $tool): string
    {
        return "`{$tool['id']}` ".self::revision($tool['revision']);
    }

    public static function revision(array $revision): string
    {
        return $revision['kind'] === 'commit' ? "at commit `{$revision['value']}`" : $revision['value'];
    }

    /**
     * @param  list<string>  $items
     */
    private static function sentence(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." and {$last}";
    }

    public static function cell(string $text): string
    {
        $firstLine = trim(explode("\n", $text, 2)[0]);
        if (mb_strlen($firstLine) > self::CELL_CHARACTERS) {
            $firstLine = mb_substr($firstLine, 0, self::CELL_CHARACTERS).'…';
        }

        return str_replace('|', '\|', $firstLine);
    }
}
