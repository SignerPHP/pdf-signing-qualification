<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Acquisition\SourceConfiguration;

final class RunReport
{
    public const string SOURCE_CONFIGURATION_FILE = 'inputs.json';

    private const array VERDICTS = ['valid', 'invalid', 'inconclusive', 'not_evaluated', 'not_applicable'];

    private const array COVERAGE_COLUMNS = [
        'unavailable' => 'Unavailable (`not_evaluated`)',
        'unsupported' => '`unsupported`',
        'rejected_as_expected' => 'Rejected as expected',
        'not_signed' => 'Not signed',
        'signed' => 'Signed',
    ];

    private const string SIGNATURE_PLACEHOLDER = '<signature>';

    private const string WORK_PATH_PATTERN = '~\\\\?/sandbox\\\\?/run-[0-9a-f]+[^\s"\',;\]]*~';

    private const string WORK_PATH_PLACEHOLDER = '<work path>';

    public static function fromDirectory(string $directory): string
    {
        $directory = rtrim($directory, '/');
        $records = RunRecords::fromDirectory($directory);
        $configurationPath = $directory.'/'.self::SOURCE_CONFIGURATION_FILE;
        $configuration = is_file($configurationPath) ? @file_get_contents($configurationPath) : false;
        if ($configuration === false) {
            throw new RuntimeException("{$directory} has no ".self::SOURCE_CONFIGURATION_FILE.', the copy of the source configuration used by the run, so its inputs have no strata to report');
        }
        if (['sha256' => hash('sha256', $configuration), 'byte_size' => strlen($configuration)] !== $records->run['source_configuration']) {
            throw new RuntimeException("{$configurationPath} is not the source configuration recorded in the run.json of {$directory}");
        }
        $inputs = SourceConfiguration::fromJson($configuration);
        /** @var array{selection: string} $declaration */
        $declaration = json_decode($configuration, true, flags: JSON_THROW_ON_ERROR);

        return self::markdown($records, $declaration['selection'], $inputs);
    }

    /**
     * @param  list<Input>  $inputs
     */
    public static function markdown(RunRecords $records, string $selection, array $inputs): string
    {
        $inputsById = [];
        foreach ($inputs as $input) {
            $inputsById[$input->id()] = $input;
        }
        self::assertOneAttemptPerInput($records->attempts, $inputsById);

        $verdicts = [];
        $observationsByAttempt = [];
        foreach ($records->observations as $observation) {
            $verdicts[$observation['attempt_id']][$observation['analyzer']['id']] = $observation['verdict'];
            $observationsByAttempt[$observation['attempt_id']][] = $observation;
        }
        $analyzerIds = array_column($records->run['analyzers'], 'id');
        $strata = SourceConfiguration::strata();
        $attemptsByStratum = array_fill_keys($strata, []);
        foreach ($records->attempts as $attempt) {
            foreach ($inputsById[$attempt['input']['source_id']]->strata as $stratum) {
                $attemptsByStratum[$stratum][] = $attempt;
            }
        }

        $lines = [
            "# Report of run {$records->run['id']}",
            '',
            "Scenario `{$records->run['scenario_id']}`, implementation ".Summary::tool($records->run['implementation']).'. Generated only from the records in the run directory: `run.json`, `attempts.json`, `observations.json` and `'.self::SOURCE_CONFIGURATION_FILE.'`, the copy of the source configuration used by the run.',
            '',
            ...self::selection($selection),
            ...($records->regressions === null ? [] : ['The regression cases replayed by the run are a failure-enriched selection of their own, so they are not counted here.', '']),
            '## Coverage by stratum and scenario',
            '',
            'Strata overlap: an input usually belongs to several strata, so the rows of a table do not add up to its last row. Every row counts all the attempts on the inputs of its stratum, so its denominator includes the inputs that were unavailable, unsupported or expected to be rejected.',
            '',
            "### Scenario `{$records->run['scenario_id']}`",
            '',
            ...self::coverage($attemptsByStratum, $records->attempts),
            '',
            '### Verdicts by stratum',
            '',
            "Each analyzer judges on its own and its verdicts are never combined with another analyzer's. Every attempt has one verdict from each analyzer, so a row has the same denominator as in the coverage table: an attempt without an output is `not_evaluated`.",
            '',
            ...self::verdictsByStratum($records->run['analyzers'], $records->observations, $verdicts, $attemptsByStratum, $records->attempts),
            '## Validator disagreements',
            '',
            ...self::disagreements($records->attempts, $observationsByAttempt),
            '',
            '## Failures',
            '',
            ...self::failures($records->attempts, $observationsByAttempt, $verdicts, $analyzerIds, $inputsById),
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array>  $attempts
     * @param  array<string, Input>  $inputsById
     */
    private static function assertOneAttemptPerInput(array $attempts, array $inputsById): void
    {
        $attemptedIds = array_map(static fn (array $attempt): string => $attempt['input']['source_id'], $attempts);
        $undeclared = array_diff($attemptedIds, array_keys($inputsById));
        if ($undeclared !== []) {
            throw new RuntimeException('The run has attempts on inputs its source configuration does not declare: '.implode(', ', $undeclared));
        }
        $notAttempted = array_diff(array_keys($inputsById), $attemptedIds);
        if ($notAttempted !== []) {
            throw new RuntimeException('The run has no attempt on these declared inputs: '.implode(', ', $notAttempted));
        }
    }

    /**
     * @return list<string>
     */
    private static function selection(string $selection): array
    {
        if ($selection === 'failure_enriched') {
            return ['**This sample is failure-enriched, so no count or share in this report is a compatibility rate.** Its inputs were chosen for the defects and edge cases they hold, not to stand for the documents found in practice.', ''];
        }
        if ($selection === 'stratified') {
            return ['**This sample is stratified, so a share in this report holds for one stratum of its pool of candidates, not for the whole pool nor for PDFs in general.** Its inputs were drawn by a quota per stratum, so rare strata weigh more here than in the pool.', ''];
        }

        return ['The source configuration declares this sample representative of the documents found in practice.', ''];
    }

    private static function coverageCategory(array $attempt): string
    {
        return match (true) {
            self::isUnavailable($attempt) => 'unavailable',
            $attempt['capability'] === 'unsupported' => 'unsupported',
            Summary::isRejectedAsExpected($attempt) => 'rejected_as_expected',
            $attempt['execution']['outcome'] === 'succeeded' => 'signed',
            default => 'not_signed',
        };
    }

    /**
     * @param  array<string, list<array>>  $attemptsByStratum
     * @param  list<array>  $attempts
     * @return list<string>
     */
    private static function coverage(array $attemptsByStratum, array $attempts): array
    {
        $lines = self::header(['Stratum', 'Attempts', ...array_values(self::COVERAGE_COLUMNS)]);
        $row = static fn (string $label, array $rowAttempts): string => self::row([$label, count($rowAttempts), ...array_values(self::countCoverage($rowAttempts))]);
        foreach ($attemptsByStratum as $stratum => $stratumAttempts) {
            $lines[] = $row("`{$stratum}`", $stratumAttempts);
        }
        $lines[] = $row('All inputs', $attempts);

        return $lines;
    }

    /**
     * @param  list<array>  $analyzers
     * @param  list<array>  $observations
     * @param  array<string, array<string, string>>  $verdicts
     * @param  array<string, list<array>>  $attemptsByStratum
     * @param  list<array>  $attempts
     * @return list<string>
     */
    private static function verdictsByStratum(array $analyzers, array $observations, array $verdicts, array $attemptsByStratum, array $attempts): array
    {
        $properties = array_column(array_map(static fn (array $observation): array => ['analyzer' => $observation['analyzer']['id'], 'property' => $observation['property']], $observations), 'property', 'analyzer');

        $lines = [];
        foreach ($analyzers as $analyzer) {
            $analyzerId = $analyzer['id'];
            $row = static function (string $label, array $rowAttempts) use ($verdicts, $analyzerId): string {
                $counts = array_fill_keys(self::VERDICTS, 0);
                foreach ($rowAttempts as $attempt) {
                    $counts[$verdicts[$attempt['id']][$analyzerId]]++;
                }

                return self::row([$label, count($rowAttempts), ...array_values($counts)]);
            };
            $property = isset($properties[$analyzerId]) ? " (`{$properties[$analyzerId]}`)" : '';
            array_push($lines, "#### `{$analyzerId}`{$property}", '', ...self::header(['Stratum', 'Attempts', ...array_map(static fn (string $verdict): string => "`{$verdict}`", self::VERDICTS)]));
            foreach ($attemptsByStratum as $stratum => $stratumAttempts) {
                $lines[] = $row("`{$stratum}`", $stratumAttempts);
            }
            $lines[] = $row('All inputs', $attempts);
            $lines[] = '';
        }

        return $lines;
    }

    /**
     * @param  list<array>  $attempts
     * @param  array<string, list<array>>  $observationsByAttempt
     * @return list<string>
     */
    private static function disagreements(array $attempts, array $observationsByAttempt): array
    {
        $rows = [];
        foreach ($attempts as $attempt) {
            $byProperty = [];
            foreach ($observationsByAttempt[$attempt['id']] ?? [] as $observation) {
                $byProperty[$observation['property']][] = $observation;
            }
            foreach ($byProperty as $property => $propertyObservations) {
                if (count(array_unique(array_column($propertyObservations, 'verdict'))) < 2) {
                    continue;
                }
                $rows[] = self::row([
                    "`{$attempt['input']['source_id']}`",
                    "`{$property}`",
                    implode(', ', array_map(static fn (array $observation): string => "`{$observation['analyzer']['id']}` `{$observation['verdict']}`", $propertyObservations)),
                ]);
            }
        }

        $total = count($attempts);
        if ($rows === []) {
            return ["No two analyzers of the same property gave different verdicts on the same attempt, out of {$total} attempts."];
        }

        return [
            'Analyzers of the same property that gave different verdicts on the same attempt. They are listed as observed, not resolved: no verdict is chosen by vote. '.count($rows)." disagreements over {$total} attempts.",
            '',
            ...self::header(['Input', 'Property', 'Verdicts'], numericFrom: 3),
            ...$rows,
        ];
    }

    private static function isUnavailable(array $attempt): bool
    {
        return $attempt['capability'] === 'not_evaluated';
    }

    /**
     * @param  list<array>  $attempts
     * @param  array<string, list<array>>  $observationsByAttempt
     * @param  array<string, array<string, string>>  $verdicts
     * @param  list<string>  $analyzerIds
     * @param  array<string, Input>  $inputsById
     * @return list<string>
     */
    private static function failures(array $attempts, array $observationsByAttempt, array $verdicts, array $analyzerIds, array $inputsById): array
    {
        $total = count($attempts);
        $causes = [];
        $failingAttempts = [];
        foreach ($attempts as $attempt) {
            $sourceId = $attempt['input']['source_id'];
            if (! self::isUnavailable($attempt) && Summary::isExecutionFailure($attempt)) {
                $outcome = $attempt['execution']['outcome'];
                $cause = match (true) {
                    $outcome === 'succeeded' => 'Expected to be rejected, but signed',
                    $attempt['execution']['error'] !== null => (string) preg_replace(self::WORK_PATH_PATTERN, self::WORK_PATH_PLACEHOLDER, self::firstLine($attempt['execution']['error']['message'])),
                    $attempt['capability'] === 'unsupported' => 'The scenario is not supported',
                    default => 'No error was recorded',
                };
                $causes["000\0\0{$outcome}\0{$cause}"][] = $sourceId;
                $failingAttempts[$attempt['id']] = true;
            }
            foreach ($observationsByAttempt[$attempt['id']] ?? [] as $observation) {
                if (! Summary::isOutputFailure($observation['verdict'])) {
                    continue;
                }
                $analyzerOrder = (int) array_search($observation['analyzer']['id'], $analyzerIds, true) + 1;
                $cause = self::outputCause($observation);
                $causes[sprintf('%03d', $analyzerOrder)."\0{$observation['analyzer']['id']}\0{$observation['verdict']}\0{$cause}"][] = $sourceId;
                $failingAttempts[$attempt['id']] = true;
            }
        }

        $lines = [
            'A failure is what the run summary lists under its failures: an execution that did not succeed and was not rejected as expected, an expected rejection that signed, and a verdict of `invalid` or `inconclusive`. An unavailable input is never a failure of the implementation, so it is only counted in the denominator.',
            '',
            ...self::header(['Attempts', ...array_values(self::COVERAGE_COLUMNS), 'With a failure'], numericFrom: 0),
            self::row([$total, ...array_values(self::countCoverage($attempts)), count($failingAttempts)]),
            '',
            '### By cause',
            '',
        ];
        if ($causes === []) {
            $lines[] = 'No attempt failed and no output was judged `invalid` or `inconclusive`.';
        } else {
            uksort($causes, static function (string $left, string $right) use ($causes): int {
                [$leftOrder] = explode("\0", $left, 2);
                [$rightOrder] = explode("\0", $right, 2);

                return [$leftOrder, count($causes[$right]), $left] <=> [$rightOrder, count($causes[$left]), $right];
            });
            array_push(
                $lines,
                'Each cause is shown whole, as it is compared. The names of signatures that an analyzer reports in its details are replaced by `'.self::SIGNATURE_PLACEHOLDER.'`, and paths in the work directory of the runner by `'.self::WORK_PATH_PLACEHOLDER.'`, because they change from one attempt to another. An attempt can show several causes, one per analyzer.',
                '',
                ...self::header(['Stage', 'Analyzer', 'Result', 'Cause', "Attempts (of {$total})", 'Inputs'], numericFrom: 4, numericTo: 4),
            );
            foreach ($causes as $key => $sourceIds) {
                [, $analyzerId, $result, $cause] = explode("\0", $key, 4);
                $lines[] = self::row([
                    $analyzerId === '' ? 'Execution' : 'Output',
                    $analyzerId === '' ? 'none' : "`{$analyzerId}`",
                    "`{$result}`",
                    str_replace('|', '\\|', $cause),
                    count($sourceIds),
                    implode(', ', array_map(static fn (string $sourceId): string => "`{$sourceId}`", $sourceIds)),
                ]);
            }
        }

        return [...$lines, '', '### By input source', '', ...self::bySource($attempts, $verdicts, $analyzerIds, $inputsById, $failingAttempts)];
    }

    /**
     * @param  list<array>  $attempts
     * @param  array<string, array<string, string>>  $verdicts
     * @param  list<string>  $analyzerIds
     * @param  array<string, Input>  $inputsById
     * @param  array<string, true>  $failingAttempts
     * @return list<string>
     */
    private static function bySource(array $attempts, array $verdicts, array $analyzerIds, array $inputsById, array $failingAttempts): array
    {
        $attemptsBySource = [];
        foreach ($attempts as $attempt) {
            $attemptsBySource[$inputsById[$attempt['input']['source_id']]->source->id][] = $attempt;
        }

        $lines = self::header(['Source', 'Attempts', ...array_values(self::COVERAGE_COLUMNS), 'Execution failures', 'With a failure']);
        foreach ($attemptsBySource as $sourceId => $sourceAttempts) {
            $executionFailures = count(array_filter($sourceAttempts, static fn (array $attempt): bool => ! self::isUnavailable($attempt) && Summary::isExecutionFailure($attempt)));
            $withFailure = count(array_filter($sourceAttempts, static fn (array $attempt): bool => isset($failingAttempts[$attempt['id']])));
            $lines[] = self::row(["`{$sourceId}`", count($sourceAttempts), ...array_values(self::countCoverage($sourceAttempts)), $executionFailures, $withFailure]);
        }

        array_push(
            $lines,
            '',
            'Outputs judged `invalid` or `inconclusive`, per analyzer and input source. Each analyzer is counted apart, so a row is not a number of attempts.',
            '',
            ...self::header(['Source', 'Attempts', ...array_map(static fn (string $analyzerId): string => "`{$analyzerId}`", $analyzerIds)]),
        );
        foreach ($attemptsBySource as $sourceId => $sourceAttempts) {
            $counts = array_map(
                static fn (string $analyzerId): int => count(array_filter($sourceAttempts, static fn (array $attempt): bool => Summary::isOutputFailure($verdicts[$attempt['id']][$analyzerId]))),
                $analyzerIds,
            );
            $lines[] = self::row(["`{$sourceId}`", count($sourceAttempts), ...$counts]);
        }

        return $lines;
    }

    /**
     * @param  list<array>  $attempts
     * @return array<string, int>
     */
    private static function countCoverage(array $attempts): array
    {
        $counts = array_fill_keys(array_keys(self::COVERAGE_COLUMNS), 0);
        foreach ($attempts as $attempt) {
            $counts[self::coverageCategory($attempt)]++;
        }

        return $counts;
    }

    private static function outputCause(array $observation): string
    {
        $cause = self::firstLine($observation['evidence']['summary']);
        $signatures = $observation['evidence']['details']['signatures'] ?? [];
        $names = [];
        foreach (is_array($signatures) ? $signatures : [] as $signature) {
            foreach (['field', 'object'] as $key) {
                if (is_array($signature) && is_string($signature[$key] ?? null) && $signature[$key] !== '') {
                    $names[] = $signature[$key];
                }
            }
        }
        usort($names, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($names as $name) {
            $cause = (string) preg_replace('/(?<![\w.])'.preg_quote($name, '/').'(?![\w.])/u', self::SIGNATURE_PLACEHOLDER, $cause);
        }

        return $cause;
    }

    private static function firstLine(string $text): string
    {
        return trim(explode("\n", $text, 2)[0]);
    }

    /**
     * @param  list<string>  $titles
     * @return list<string>
     */
    private static function header(array $titles, int $numericFrom = 1, ?int $numericTo = null): array
    {
        $numericTo ??= count($titles) - 1;
        $separators = [];
        foreach ($titles as $index => $title) {
            $width = max(3, mb_strlen($title));
            $separators[] = $index >= $numericFrom && $index <= $numericTo ? str_repeat('-', $width - 1).':' : str_repeat('-', $width);
        }

        return [self::row($titles), self::row($separators)];
    }

    /**
     * @param  list<string|int>  $cells
     */
    private static function row(array $cells): string
    {
        return '| '.implode(' | ', $cells).' |';
    }
}
