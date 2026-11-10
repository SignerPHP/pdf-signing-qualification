<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

final class DifferentialSummary
{
    private const array DIFFERENCE_SECTIONS = [
        'capability_mismatch' => 'Capability mismatches',
        'expected_rejection' => 'Expected rejections',
        'validator_disagreement' => 'Validator disagreements',
        'unresolved_finding' => 'Unresolved findings',
    ];

    public static function markdown(array $comparison): string
    {
        $first = $comparison['first'];
        $second = $comparison['second'];
        $names = ['first' => $first['implementation']['id'], 'second' => $second['implementation']['id']];
        $title = "# Differential comparison of `{$names['first']}` and `{$names['second']}`";

        $lines = [
            $title,
            '',
            "Scenario `{$comparison['scenario_id']}`, ".Summary::tool($first['implementation']).' and '.Summary::tool($second['implementation']).', two runs each over the same inputs, analyzers and limits.',
            '',
            ...RunReport::selection($comparison['selection']),
            '## Runs',
            '',
            '| Implementation | Run | Container image | Compatibility with the adapter |',
            '| -------------- | --- | --------------- | ------------------------------ |',
        ];
        foreach ([$first, $second] as $side) {
            foreach ($side['runs'] as $run) {
                $image = $run['container_image']['status'] === 'available' ? "`{$run['container_image']['id']}`" : 'unavailable: '.self::cell($run['container_image']['reason']);
                $compatibility = $run['implementation_compatibility'] === null ? 'not recorded' : "`{$run['implementation_compatibility']['status']}`";
                $lines[] = "| `{$side['implementation']['id']}` | `{$run['run_id']}` | {$image} | {$compatibility} |";
            }
        }

        $lines = [
            ...$lines,
            '',
            '## Analyzers',
            '',
            'An analyzer with a baseline also judged each input before signing; the others compare the output with its input.',
            '',
            '| Analyzer | Revision | Property | Baseline |',
            '| -------- | -------- | -------- | -------- |',
            ...array_map(static fn (array $analyzer): string => "| `{$analyzer['id']}` | ".Summary::revision($analyzer['revision'])." | `{$analyzer['property']}` | ".($analyzer['baseline'] ? 'yes' : 'no').' |', $comparison['analyzers']),
            '',
            '## Denominators',
            '',
            'Every attempt of every run, so the columns add up to the attempts.',
            '',
            '| Implementation | Run | Attempts | '.implode(' | ', RunReport::COVERAGE_COLUMNS).' |',
            '| -------------- | --- | -------: | '.implode(' | ', array_map(static fn (string $column): string => str_repeat('-', max(3, mb_strlen($column)) - 1).':', RunReport::COVERAGE_COLUMNS)).' |',
        ];
        foreach ([$first, $second] as $side) {
            foreach ($side['runs'] as $run) {
                $coverage = $run['coverage'];
                $lines[] = "| `{$side['implementation']['id']}` | `{$run['run_id']}` | {$coverage['total']} | ".implode(' | ', array_map(static fn (string $column): int => $coverage[$column], array_keys(RunReport::COVERAGE_COLUMNS))).' |';
            }
        }

        $lines = [
            ...$lines,
            '',
            '## Classification',
            '',
            'An input is paired when it is available in all four runs with the same content. Each paired input has a capability item, an execution item and, when every one of its four attempts signed, one verdict item per analyzer. An item with the same value in all four runs is an `agreement`, or a `shared_failure` when that value is a failure. On an expected rejection, a failure that rejected the input and one that did not are different values. Any other item is a difference, classified by the first rule that applies: `capability_mismatch` when the capabilities of the attempts differ; `unresolved_finding` when the two runs of one implementation differ, so the difference was not reproduced; `reproducible_implementation_defect` when the input is declared as an expected rejection and one implementation rejected it in both runs while the other did not in both; `expected_rejection` for any other difference on such an input; `validator_disagreement` when the analyzers of the same property disagree on one of the four outputs; `reproducible_implementation_defect` when one implementation fails the item in both runs, the other passes it in both, and the input does not already fail it before signing; and `unresolved_finding` otherwise. An input counts under `agreement` when all its items agree, and under each other class when one of its items has it.',
            '',
            '| Input status | Inputs |',
            '| ------------ | -----: |',
            ...array_map(static fn (string $status, int $count): string => "| `{$status}` | {$count} |", array_keys($comparison['totals']['inputs']), $comparison['totals']['inputs']),
            '',
            '| Class | Items | Inputs |',
            '| ----- | ----: | -----: |',
            ...array_map(static fn (string $classification): string => "| `{$classification}` | {$comparison['totals']['items'][$classification]} | {$comparison['totals']['inputs_by_classification'][$classification]} |", DifferentialComparison::CLASSIFICATIONS),
            '',
            ...self::defects($comparison, $names),
        ];

        foreach (self::DIFFERENCE_SECTIONS as $classification => $sectionTitle) {
            $rows = [];
            foreach ($comparison['inputs'] as $input) {
                foreach ($input['items'] as $item) {
                    if ($item['classification'] === $classification) {
                        $rows[] = "| `{$input['source_id']}` | ".self::subject($item).' | '.self::values($item['first']).' | '.self::values($item['second']).' | '.self::cell($item['reason']).' |';
                    }
                }
            }
            if ($rows === []) {
                continue;
            }
            $lines = [
                ...$lines,
                "## {$sectionTitle}",
                '',
                "| Input | Item | `{$names['first']}` | `{$names['second']}` | Reason |",
                '| ----- | ---- | '.str_repeat('-', strlen($names['first']) + 2).' | '.str_repeat('-', strlen($names['second']) + 2).' | ------ |',
                ...$rows,
                '',
            ];
        }

        $sharedRows = [];
        foreach ($comparison['inputs'] as $input) {
            foreach ($input['items'] as $item) {
                if ($item['classification'] === 'shared_failure') {
                    $sharedRows[] = "| `{$input['source_id']}` | ".self::subject($item)." | `{$item['first'][0]}` |";
                }
            }
        }
        if ($sharedRows !== []) {
            $lines = [
                ...$lines,
                '## Shared failures',
                '',
                'Both implementations fail these items with the same value in every run. They are not differences, and the agreement does not make them a problem of the input.',
                '',
                '| Input | Item | Value |',
                '| ----- | ---- | ----- |',
                ...$sharedRows,
                '',
            ];
        }

        $unpaired = array_filter($comparison['inputs'], static fn (array $input): bool => $input['status'] !== 'paired');
        if ($unpaired !== []) {
            array_push($lines, '## Inputs not compared', '', '| Input | Status |', '| ----- | ------ |');
            foreach ($unpaired as $input) {
                $lines[] = "| `{$input['source_id']}` | `{$input['status']}` |";
            }
            $lines[] = '';
        }

        $lines = [
            ...$lines,
            '## Limits',
            '',
            '- Agreement between the implementations does not establish conformance: both can be wrong in the same way, and the analyzers judge only the properties listed above.',
            '- Disagreements between analyzers are kept as they are and never settled by vote; an item they affect is not attributed to an implementation.',
            '- A defect is reproduced by two runs in the same environment and image, so it says nothing about other environments, and a difference that one run did not repeat stays unresolved.',
            '- A failure that the input already has before signing is not attributed to the implementation, even when the other one passes it.',
            '- Output hashes are not compared, because the certificate and the signature field change on every run, and regression cases are not counted.',
            '- No implementation or analyzer is given a password, so encrypted inputs that need one are judged without it.',
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $names
     * @return list<string>
     */
    private static function defects(array $comparison, array $names): array
    {
        $lines = ['## Reproducible implementation defects', ''];
        $found = false;
        foreach (['first' => 'second', 'second' => 'first'] as $side => $otherSide) {
            $defects = [];
            foreach ($comparison['inputs'] as $input) {
                foreach ($input['items'] as $item) {
                    if (($item['defective_side'] ?? null) === $side) {
                        $defects[] = [$input, $item];
                    }
                }
            }
            if ($defects === []) {
                continue;
            }
            $found = true;
            $lines = [
                ...$lines,
                "### `{$names[$side]}`",
                '',
                "| Input | Item | `{$names[$side]}` | `{$names[$otherSide]}` |",
                '| ----- | ---- | '.str_repeat('-', strlen($names[$side]) + 2).' | '.str_repeat('-', strlen($names[$otherSide]) + 2).' |',
            ];
            foreach ($defects as [$input, $item]) {
                $lines[] = "| `{$input['source_id']}` | ".self::subject($item).' | '.self::values($item[$side]).' | '.self::values($item[$otherSide]).' |';
            }
            array_push($lines, '', 'Evidence of each defect: the attempts of both runs of each implementation and, for a verdict, the observations on their outputs, in `attempts.json` and `observations.json` of each run.', '');
            foreach ($defects as [$input, $item]) {
                $evidence = [...$input['first_attempt_ids'], ...$input['second_attempt_ids'], ...$item['observation_ids']];
                $lines[] = "- `{$input['source_id']}`, ".self::subject($item).': '.implode(', ', array_map(static fn (string $id): string => "`{$id}`", $evidence));
            }
            $lines[] = '';
        }
        if (! $found) {
            array_push($lines, 'No difference meets the conditions of a reproducible implementation defect.', '');
        }

        return $lines;
    }

    private static function subject(array $item): string
    {
        return $item['subject'] === 'verdict' ? "verdict of `{$item['analyzer_id']}`" : $item['subject'];
    }

    /**
     * @param  list<string>  $values
     */
    private static function values(array $values): string
    {
        return $values[0] === $values[1] ? "`{$values[0]}`" : "`{$values[0]}` then `{$values[1]}`";
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }
}
