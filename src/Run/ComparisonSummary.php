<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

final class ComparisonSummary
{
    private const array CHANGE_SECTIONS = [
        'introduced' => 'New failures',
        'fixed' => 'Fixed',
        'persistent' => 'Persistent failures',
        'changed' => 'Other changes',
    ];

    public static function markdown(array $comparison): string
    {
        $reference = $comparison['reference'];
        $candidate = $comparison['candidate'];
        $lines = [
            "# Comparison of {$reference['run_id']} with {$candidate['run_id']}",
            '',
            "Reference run `{$reference['run_id']}`: scenario `{$reference['scenario_id']}`, implementation ".self::tool($reference['implementation']).'.',
            "Candidate run `{$candidate['run_id']}`: scenario `{$candidate['scenario_id']}`, implementation ".self::tool($candidate['implementation']).'.',
            '',
            'Attempts are paired by input and scenario. An item is `introduced` when the candidate run fails it and the reference run did not, `fixed` when the reference run failed it and the candidate run executed it successfully or judged it `valid`, `persistent` when both runs fail it, and `changed` for any other difference. A failure is an execution that failed or timed out, unless it was a rejection that was expected, or a verdict of `invalid`. Output hashes are not compared.',
            '',
            '## Differences between the runs',
            '',
            ...self::differences($comparison['differences']),
            '',
            '## Totals',
            '',
            '| Input status | Inputs |',
            '| ------------ | -----: |',
            ...array_map(static fn (string $status, int $count): string => "| `{$status}` | {$count} |", array_keys($comparison['totals']['inputs']), $comparison['totals']['inputs']),
            '',
            '| Change | Items |',
            '| ------ | ----: |',
            ...array_map(static fn (string $change, int $count): string => "| `{$change}` | {$count} |", array_keys($comparison['totals']['changes']), $comparison['totals']['changes']),
            '',
        ];

        foreach (self::CHANGE_SECTIONS as $change => $title) {
            $rows = [];
            foreach ($comparison['inputs'] as $input) {
                foreach ($input['items'] as $item) {
                    if ($item['change'] === $change) {
                        $subject = $item['subject'] === 'verdict' ? "verdict of `{$item['analyzer_id']}`" : $item['subject'];
                        $rows[] = "| `{$input['source_id']}` | {$subject} | `{$item['reference']}` | `{$item['candidate']}` |";
                    }
                }
            }
            if ($rows === [] && $change !== 'introduced') {
                continue;
            }
            array_push($lines, "## {$title}", '');
            if ($rows === []) {
                array_push($lines, 'The candidate run has no failure that the reference run did not have.', '');

                continue;
            }
            array_push($lines, '| Input | Item | Reference | Candidate |', '| ----- | ---- | --------- | --------- |', ...$rows);
            $lines[] = '';
        }

        $unpaired = array_filter($comparison['inputs'], static fn (array $input): bool => $input['status'] !== 'paired');
        if ($unpaired !== []) {
            array_push(
                $lines,
                '## Inputs not compared',
                '',
                'These inputs are not paired, so nothing in them is counted as fixed, persistent or introduced.',
                '',
                '| Input | Scenario | Status | Reference | Candidate |',
                '| ----- | -------- | ------ | --------- | --------- |',
            );
            foreach ($unpaired as $input) {
                $lines[] = "| `{$input['source_id']}` | `{$input['scenario_id']}` | `{$input['status']}` | ".self::side($input['reference']).' | '.self::side($input['candidate']).' |';
            }
            $lines[] = '';
        }

        $introduced = $comparison['totals']['changes']['introduced'];
        $lines[] = $introduced === 0 ? 'The candidate run introduced no failure.' : "The candidate run introduced {$introduced} failure(s).";

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array>  $differences
     * @return list<string>
     */
    private static function differences(array $differences): array
    {
        if ($differences === []) {
            return ['The two runs have the same scenario, implementation and implementation runtime, analyzer revisions and policies, source configuration, expected rejections, container image and resource limits.'];
        }

        $lines = ['| Subject | Analyzer | Reference | Candidate |', '| ------- | -------- | --------- | --------- |'];
        foreach ($differences as $difference) {
            $analyzer = isset($difference['analyzer_id']) ? "`{$difference['analyzer_id']}`" : '';
            $lines[] = "| `{$difference['subject']}` | {$analyzer} | ".self::value($difference['subject'], $difference['reference']).' | '.self::value($difference['subject'], $difference['candidate']).' |';
        }

        return $lines;
    }

    private static function side(?array $side): string
    {
        if ($side === null) {
            return 'absent';
        }
        $input = $side['input'];

        return $input['status'] === 'available'
            ? "SHA-256 `{$input['sha256']}`"
            : "unavailable (`{$input['reason']}`)";
    }

    private static function value(string $subject, mixed $value): string
    {
        if ($value === null) {
            return 'absent';
        }

        return match ($subject) {
            'implementation' => self::tool($value),
            'analyzer' => self::revision($value),
            'source_configuration', 'expected_rejections' => "SHA-256 `{$value['sha256']}`, {$value['byte_size']} bytes",
            default => '`'.str_replace('|', '\|', is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)).'`',
        };
    }

    private static function tool(array $tool): string
    {
        return "`{$tool['id']}` ".self::revision($tool['revision']);
    }

    private static function revision(array $revision): string
    {
        return $revision['kind'] === 'commit' ? "at commit `{$revision['value']}`" : $revision['value'];
    }
}
