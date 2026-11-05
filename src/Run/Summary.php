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
     */
    public static function markdown(array $run, array $attempts, array $observations): string
    {
        $sourceIds = array_column(array_map(static fn (array $attempt): array => ['id' => $attempt['id'], 'source_id' => $attempt['input']['source_id']], $attempts), 'source_id', 'id');

        $lines = [
            "# Run {$run['id']}",
            '',
            "Scenario `{$run['scenario_id']}`, implementation ".self::tool($run['implementation']).', analyzers '.self::sentence(array_values(array_map(self::tool(...), $run['analyzers']))).'.',
            '',
            ...self::executions($attempts),
            '',
            ...self::verdicts($run['analyzers'], $observations),
            '',
            '## Failures',
            '',
            ...self::failures($attempts, $observations, $sourceIds),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array>  $attempts
     * @return list<string>
     */
    private static function executions(array $attempts): array
    {
        $counts = array_fill_keys(self::OUTCOMES, 0);
        foreach ($attempts as $attempt) {
            $counts[$attempt['execution']['outcome']]++;
        }

        $lines = ['## Executions', '', '| Outcome | Attempts |', '| ------- | -------: |'];
        foreach ($counts as $outcome => $count) {
            $lines[] = "| `{$outcome}` | {$count} |";
        }
        $lines[] = '| Total | '.count($attempts).' |';

        return $lines;
    }

    /**
     * @param  list<array>  $analyzers
     * @param  list<array>  $observations
     * @return list<string>
     */
    private static function verdicts(array $analyzers, array $observations): array
    {
        $counts = array_fill_keys(array_column($analyzers, 'id'), array_fill_keys(self::VERDICTS, 0));
        foreach ($observations as $observation) {
            $counts[$observation['analyzer']['id']][$observation['verdict']]++;
        }

        $lines = [
            '## Verdicts',
            '',
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
     * @return list<string>
     */
    private static function failures(array $attempts, array $observations, array $sourceIds): array
    {
        $unsuccessfulAttempts = array_filter($attempts, static fn (array $attempt): bool => $attempt['execution']['outcome'] !== 'succeeded');
        $judgedOutputs = array_filter($observations, static fn (array $observation): bool => in_array($observation['verdict'], ['invalid', 'inconclusive'], true));
        if ($unsuccessfulAttempts === [] && $judgedOutputs === []) {
            return ['Every attempt succeeded and every output was judged valid.'];
        }

        $lines = [];
        if ($unsuccessfulAttempts !== []) {
            array_push($lines, '### Executions that did not succeed', '', '| Input | Capability | Outcome | Error |', '| ----- | ---------- | ------- | ----- |');
            foreach ($unsuccessfulAttempts as $attempt) {
                $lines[] = "| `{$attempt['input']['source_id']}` | `{$attempt['capability']}` | `{$attempt['execution']['outcome']}` | ".self::cell((string) ($attempt['execution']['error']['message'] ?? '')).' |';
            }
            $lines[] = '';
        }
        if ($judgedOutputs !== []) {
            array_push($lines, '### Outputs not judged valid', '', '| Input | Analyzer | Verdict | Summary |', '| ----- | -------- | ------- | ------- |');
            foreach ($judgedOutputs as $observation) {
                $lines[] = "| `{$sourceIds[$observation['attempt_id']]}` | `{$observation['analyzer']['id']}` | `{$observation['verdict']}` | ".self::cell($observation['evidence']['summary']).' |';
            }
            $lines[] = '';
        }
        array_pop($lines);

        return $lines;
    }

    private static function tool(array $tool): string
    {
        return "`{$tool['id']}` ".($tool['revision']['kind'] === 'commit' ? "at commit `{$tool['revision']['value']}`" : $tool['revision']['value']);
    }

    /**
     * @param  list<string>  $items
     */
    private static function sentence(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." and {$last}";
    }

    private static function cell(string $text): string
    {
        $firstLine = trim(explode("\n", $text, 2)[0]);
        if (mb_strlen($firstLine) > self::CELL_CHARACTERS) {
            $firstLine = mb_substr($firstLine, 0, self::CELL_CHARACTERS).'…';
        }

        return str_replace('|', '\|', $firstLine);
    }
}
