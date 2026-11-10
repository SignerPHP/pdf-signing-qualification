<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use SignerPHP\PdfSigningQualification\Run\Summary;

final class PerformanceSummary
{
    private const array METRIC_NAMES = [
        'wall_time_seconds' => 'Wall time',
        'cpu_seconds' => 'CPU time, user and system',
        'peak_memory_bytes' => 'Peak memory',
    ];

    private const array EXCLUSION_NAMES = [
        'input_unavailable' => 'Input unavailable in a run',
        'not_attempted' => 'Not supported, or an expected rejection, in a run',
        'execution_not_succeeded' => 'Execution failed or timed out in a run',
        'output_not_valid' => 'Output not judged valid by every analyzer in a run',
        'measurement_unavailable' => 'Measurement unavailable in a run',
    ];

    public static function markdown(array $analysis): string
    {
        [$first, $second] = $analysis['implementations'];
        $firstId = $first['id'];
        $secondId = $second['id'];
        $environment = $analysis['environment'];
        $cpuModel = ($environment['cpu_model']['status'] ?? null) === 'available' ? $environment['cpu_model']['name'] : 'unavailable';
        $image = $environment['container_image']['status'] === 'available' ? "`{$environment['container_image']['id']}`" : 'unavailable';

        $lines = [
            "# Paired performance of `{$firstId}` and `{$secondId}`",
            '',
            'Implementations: '.Summary::tool($first).' and '.Summary::tool($second).". Ratios are `{$firstId}` over `{$secondId}`, and differences `{$firstId}` minus `{$secondId}`.",
            '',
            "Host CPU: {$cpuModel}. Container image: {$image}. Protocol SHA-256 `{$analysis['protocol']['sha256']}`.",
            '',
            "Every metric is measured on the process tree of the attempt, from the start of the implementation's command to its end, so it includes the startup and setup of each implementation: the PHP process of `pdf-signer`, and for JSignPdf the PHP script that drives it and the JVM it starts. The fixed-cost reference below gives that cost on a minimal document.",
            '',
            '## Eligibility',
            '',
            $analysis['eligibility']['rule'],
            '',
            '| Inputs | Count |',
            '| ------ | ----: |',
            "| Population | {$analysis['eligibility']['inputs']} |",
            "| Eligible for the paired analysis | {$analysis['eligibility']['eligible']} |",
        ];
        foreach ($analysis['eligibility']['excluded'] as $exclusion => $count) {
            $lines[] = '| Excluded: '.self::EXCLUSION_NAMES[$exclusion]." | {$count} |";
        }

        $lines = [
            ...$lines,
            '',
            '## Coverage of the whole population',
            '',
            'Over every measured run of every session analyzed, eligible or not. Each rate has its denominator.',
            '',
            '| Implementation | Measured runs | Attempts | Succeeded | Failed | Timed out | Not run | Valid outputs | Inputs valid in every run | Inputs valid in some runs | Inputs never valid |',
            '| -------------- | ------------: | -------: | --------: | -----: | --------: | ------: | ------------: | ------------------------: | ------------------------: | -----------------: |',
        ];
        foreach ($analysis['coverage'] as $implementationId => $coverage) {
            $attempts = $coverage['attempts'];
            $inputs = $coverage['inputs'];
            $lines[] = "| `{$implementationId}` | {$coverage['runs']} | {$attempts} | ".implode(' | ', [
                self::rate($coverage['outcomes']['succeeded'], $attempts),
                self::rate($coverage['outcomes']['failed'], $attempts),
                self::rate($coverage['outcomes']['timed_out'], $attempts),
                self::rate($coverage['outcomes']['not_run'], $attempts),
                self::rate($coverage['valid_outputs'], $attempts),
                self::rate($coverage['inputs_by_validity']['valid_in_every_run'], $inputs),
                self::rate($coverage['inputs_by_validity']['valid_in_some_runs'], $inputs),
                self::rate($coverage['inputs_by_validity']['never_valid'], $inputs),
            ]).' |';
        }

        foreach ($analysis['sessions'] as $session) {
            array_push($lines, ...self::session($session, $firstId, $secondId, $analysis['eligibility']['eligible']));
        }

        if ($analysis['repeatability'] !== null) {
            $repeatability = $analysis['repeatability'];
            $percent = self::number($repeatability['tolerance'] * 100, 1);
            $lines = [
                ...$lines,
                '',
                '## Repeatability',
                '',
                "The later session repeats the earlier one when every statistic below can be compared and changes by at most {$percent}%: ".($repeatability['within_tolerance'] ? '**it does**.' : '**it does not**.'),
                '',
                '| Metric | Statistic | Earlier session | Later session | Change | Within tolerance |',
                '| ------ | --------- | --------------: | ------------: | -----: | ---------------- |',
            ];
            foreach ($repeatability['items'] as $item) {
                $ratio = str_contains($item['statistic'], 'ratio');
                $earlier = $item['earlier'] === null ? 'undefined' : self::value($item['metric'], $item['earlier'], $ratio);
                $later = $item['later'] === null ? 'undefined' : self::value($item['metric'], $item['later'], $ratio);
                $change = $item['relative_change'] === null ? 'not comparable' : self::signedPercent($item['relative_change']);
                $lines[] = '| '.self::METRIC_NAMES[$item['metric']]." | {$item['statistic']} | {$earlier} | {$later} | {$change} | ".($item['within_tolerance'] ? 'yes' : 'no').' |';
            }
        }

        $lines = [
            ...$lines,
            '',
            '## Limits',
            '',
            "These results describe one host, one scenario, the inputs eligible under the rule above and the revisions named here. They are paired distributions, not a ranking: no implementation is declared faster or lighter in general. A ratio below 1 says that `{$firstId}` used less of a resource than `{$secondId}` on the typical eligible document of this panel, on this host; inputs either implementation did not sign validly are outside the timings and appear only in the coverage. The process tree boundary includes the startup of each runtime, so short documents weigh that fixed cost more. Peak memory is the largest peak of one process of the tree, not the sum of the processes running at the same time. LibreSign or Nextcloud throughput and internal Java metrics are out of scope.",
            '',
        ];

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function session(array $session, string $firstId, string $secondId, int $eligible): array
    {
        $name = $session['session'] === Protocol::CALIBRATION ? 'Calibration session' : "Session {$session['session']}";
        $lines = [
            '',
            "## {$name}",
            '',
            "Study `{$session['study_id']}`, seed {$session['seed']}, {$session['measured_runs'][$firstId]} measured runs of `{$firstId}` and {$session['measured_runs'][$secondId]} of `{$secondId}`. Each document's value is its median over the measured runs; the distributions are over the {$eligible} eligible documents, and each interval is the bootstrap percentile interval of the median, resampling documents.",
        ];

        foreach (self::METRIC_NAMES as $metric => $metricName) {
            $paired = $session['metrics'][$metric];
            $lines = [...$lines, '', "### {$metricName}", ''];
            if ($paired['implementations'] === null) {
                $lines[] = 'No input is eligible, so there is no paired distribution.';

                continue;
            }
            $firstDistribution = $paired['implementations'][$firstId];
            $secondDistribution = $paired['implementations'][$secondId];
            $lines = [
                ...$lines,
                "| Statistic | `{$firstId}` | `{$secondId}` | Paired ratio | Paired difference |",
                '| --------- | -----------: | -----------: | -----------: | ----------------: |',
                '| Median | '.self::estimate($metric, $firstDistribution).' | '.self::estimate($metric, $secondDistribution).' | '.($paired['ratio'] === null ? 'undefined' : self::estimate($metric, $paired['ratio'], true)).' | '.self::estimate($metric, $paired['difference']).' |',
            ];
            foreach ($firstDistribution['quantiles'] as $position => $quantile) {
                $label = 'Quantile '.self::number($quantile['probability'], 2);
                $lines[] = "| {$label} | ".self::value($metric, $quantile['value']).' | '.self::value($metric, $secondDistribution['quantiles'][$position]['value']).' | '.($paired['ratio'] === null ? 'undefined' : self::value($metric, $paired['ratio']['quantiles'][$position]['value'], true)).' | '.self::value($metric, $paired['difference']['quantiles'][$position]['value']).' |';
            }
            $lines[] = '';
            $lines[] = "`{$firstId}` is lower on {$paired['difference']['first_lower']} of {$paired['difference']['documents']} documents.";
            $dispersion = array_map(static fn (?array $variation): string => $variation === null ? 'undefined' : self::percent($variation['median']).' median, '.self::percent($variation['maximum']).' largest', $paired['dispersion']);
            $lines[] = "Coefficient of variation of a document over the measured runs: `{$firstId}` {$dispersion[$firstId]}; `{$secondId}` {$dispersion[$secondId]}.";
        }

        $lines = [
            ...$lines,
            '',
            '### Fixed-cost reference',
            '',
            'The minimal synthetic document signed after every measured run, so the startup and setup of each implementation show without the work of a real document.',
            '',
            '| Implementation | Attempts | Valid and measured | Wall time, median | CPU time, median | Peak memory, median |',
            '| -------------- | -------: | -----------------: | ----------------: | ---------------: | ------------------: |',
        ];
        foreach ($session['references'] as $implementationId => $reference) {
            $medians = array_map(static fn (string $metric): string => $reference['metrics'][$metric] === null ? 'unavailable' : self::value($metric, $reference['metrics'][$metric]['median']), array_keys(self::METRIC_NAMES));
            $lines[] = "| `{$implementationId}` | {$reference['attempts']} | ".self::rate($reference['valid_and_measured'], $reference['attempts']).' | '.implode(' | ', $medians).' |';
        }

        $lines = [
            ...$lines,
            '',
            '### Runs in execution order',
            '',
            'The medians are over the eligible documents of each run, warm-up runs included, so a drift along the session shows here.',
            '',
            '| Run | Implementation | Role | Started | Wall time | CPU time | Peak memory | CPUs | Governors | Boost | Load, 1 min | Violations |',
            '| --- | -------------- | ---- | ------- | --------: | -------: | ----------: | ---- | --------- | ----- | ----------: | ---------: |',
        ];
        foreach ($session['runs'] as $run) {
            $medians = array_map(static fn (string $metric): string => $run['medians'][$metric] === null ? 'n/a' : self::value($metric, $run['medians'][$metric]), array_keys(self::METRIC_NAMES));
            $controls = $run['controls'];
            $lines[] = "| `{$run['label']}` | `{$run['implementation_id']}` | {$run['role']} | {$run['started_at']} | ".implode(' | ', $medians).' | '.($controls['cpuset'] ?? 'unavailable').' | '.implode(', ', $controls['scaling_governors'])." | {$controls['boost']} | ".($controls['load_average'] === null ? 'unavailable' : self::number($controls['load_average'][0], 2)).' | '.count($controls['violations']).' |';
        }

        return $lines;
    }

    private static function estimate(string $metric, array $distribution, bool $ratio = false): string
    {
        return self::value($metric, $distribution['median'], $ratio).' ('.self::value($metric, $distribution['interval'][0], $ratio).' to '.self::value($metric, $distribution['interval'][1], $ratio).')';
    }

    private static function value(string $metric, float|int $value, bool $ratio = false): string
    {
        if ($ratio) {
            return self::number($value, 3);
        }

        return $metric === 'peak_memory_bytes' ? self::number($value / 1_048_576, 1).' MiB' : self::number($value, 3).' s';
    }

    private static function rate(int $count, int $total): string
    {
        return $total === 0 ? "{$count} of 0" : "{$count} of {$total} (".self::number(100 * $count / $total, 1).'%)';
    }

    private static function percent(float $value): string
    {
        return self::number(100 * $value, 1).'%';
    }

    private static function signedPercent(float $value): string
    {
        return ($value >= 0 ? '+' : '').self::number(100 * $value, 1).'%';
    }

    private static function number(float|int $value, int $decimals): string
    {
        return number_format((float) $value, $decimals, '.', '');
    }
}
