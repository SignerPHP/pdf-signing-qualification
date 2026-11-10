<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Run\RunRecords;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class PerformanceAnalysis
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/performance.schema.json';

    public const array METRICS = ['wall_time_seconds', 'cpu_seconds', 'peak_memory_bytes'];

    public const array EXCLUSIONS = ['input_unavailable', 'not_attempted', 'execution_not_succeeded', 'output_not_valid', 'measurement_unavailable'];

    private const array MEASURED_ROLES = ['calibration', 'measured'];

    private const array ACCEPTED_VERDICTS = ['valid', 'not_applicable'];

    private const array OUTCOMES = ['succeeded', 'failed', 'timed_out', 'not_run'];

    /**
     * @param  list<StudyRecords>  $studies
     */
    public static function analyze(array $studies): array
    {
        self::assertAnalyzable($studies);
        usort($studies, static fn (StudyRecords $earlier, StudyRecords $later): int => self::sessionOrder($earlier) <=> self::sessionOrder($later));
        $protocol = $studies[0]->protocol;
        [$first, $second] = $protocol->implementations();

        $indexesByLabel = [];
        $indexes = [];
        foreach ($studies as $studyIndex => $study) {
            foreach ($study->study['runs'] as $planned) {
                $index = self::index($study->runs[$planned['label']]);
                $indexesByLabel[$studyIndex][$planned['label']] = $index;
                if (in_array($planned['role'], self::MEASURED_ROLES, true)) {
                    $indexes[$studyIndex][$planned['implementation_id']][] = $index;
                }
            }
        }
        $sourceIds = array_map(static fn (array $attempt): string => $attempt['input']['source_id'], array_values($studies[0]->runs)[0]->attempts);

        $exclusions = array_fill_keys(self::EXCLUSIONS, 0);
        $eligible = [];
        foreach ($sourceIds as $sourceId) {
            $failed = [];
            foreach ($indexes as $byImplementation) {
                foreach ($byImplementation as $runIndexes) {
                    foreach ($runIndexes as $index) {
                        $failed[] = self::exclusion($index[$sourceId]);
                    }
                }
            }
            $exclusion = null;
            foreach (self::EXCLUSIONS as $condition) {
                if (in_array($condition, $failed, true)) {
                    $exclusion = $condition;

                    break;
                }
            }
            if ($exclusion === null) {
                $eligible[] = $sourceId;
            } else {
                $exclusions[$exclusion]++;
            }
        }

        $coverage = [];
        foreach ([$first, $second] as $implementationId) {
            $coverage[$implementationId] = self::coverage($sourceIds, array_merge(...array_map(static fn (array $byImplementation): array => $byImplementation[$implementationId], $indexes)));
        }

        $sessions = [];
        foreach ($studies as $studyIndex => $study) {
            $sessions[] = self::session($study, $indexes[$studyIndex], $indexesByLabel[$studyIndex], $eligible, $first, $second);
        }

        $analysis = [
            'schema' => self::SCHEMA,
            'protocol' => $studies[0]->study['protocol'],
            'implementations' => array_map(static function (string $implementationId) use ($studies): array {
                $run = $studies[0]->runs[self::firstLabel($studies[0], $implementationId)]->run;

                return $run['implementation'] + ['runtime' => $run['implementation_runtime'] ?? null];
            }, [$first, $second]),
            'environment' => [
                'os' => array_values($studies[0]->runs)[0]->run['environment']['os'],
                'cpu_model' => array_values($studies[0]->runs)[0]->run['environment']['cpu_model'] ?? null,
                'container_image' => array_values($studies[0]->runs)[0]->run['environment']['container_image'],
                'resource_limits' => array_values($studies[0]->runs)[0]->run['environment']['resource_limits'] ?? null,
            ],
            'eligibility' => [
                'rule' => $protocol->document['eligibility'],
                'inputs' => count($sourceIds),
                'eligible' => count($eligible),
                'excluded' => $exclusions,
                'eligible_inputs' => $eligible,
            ],
            'coverage' => $coverage,
            'sessions' => $sessions,
            'repeatability' => count($sessions) === 2 ? self::repeatability($sessions[0], $sessions[1], $protocol->document['repeatability_tolerance'], $first, $second) : null,
        ];
        (new SchemaValidator)->assertValid($analysis, self::SCHEMA, 'performance analysis');

        return $analysis;
    }

    /**
     * @param  list<StudyRecords>  $studies
     */
    private static function assertAnalyzable(array $studies): void
    {
        if ($studies === [] || count($studies) > 2) {
            throw new RuntimeException('Give one session, or two measured sessions to check that the result repeats');
        }
        $sessions = array_map(static fn (StudyRecords $study): string|int => $study->study['session'], $studies);
        if (in_array(Protocol::CALIBRATION, $sessions, true) && count($studies) !== 1) {
            throw new RuntimeException('A calibration session is analyzed alone');
        }
        if (count(array_unique($sessions)) !== count($sessions)) {
            throw new RuntimeException('The same session is given twice');
        }
        foreach ($studies as $study) {
            if ($study->protocol->contents !== $studies[0]->protocol->contents) {
                throw new RuntimeException("The sessions {$studies[0]->study['id']} and {$study->study['id']} follow different protocols, so they do not repeat each other");
            }
        }

        $implementationIds = $studies[0]->protocol->implementations();
        $reference = null;
        $byImplementation = [];
        foreach ($studies as $study) {
            foreach ($study->runs as $records) {
                if (! in_array($records->run['implementation']['id'], $implementationIds, true)) {
                    throw new RuntimeException("The run {$records->run['id']} is of {$records->run['implementation']['id']}, which the protocol does not compare");
                }
                $settings = self::settings($records);
                $reference ??= [$records->run['id'], $settings];
                foreach ($settings as $setting => $value) {
                    if ($value !== $reference[1][$setting]) {
                        throw new RuntimeException("The runs {$reference[0]} and {$records->run['id']} differ in their {$setting}, so a difference between them could not be attributed to the implementations");
                    }
                }
                $implementation = [$records->run['implementation'], $records->run['implementation_runtime'] ?? null];
                $implementationId = $records->run['implementation']['id'];
                $byImplementation[$implementationId] ??= [$records->run['id'], $implementation];
                if ($implementation !== $byImplementation[$implementationId][1]) {
                    throw new RuntimeException("The runs {$byImplementation[$implementationId][0]} and {$records->run['id']} are of {$implementationId} at a different revision or runtime");
                }
            }
        }
    }

    private static function sessionOrder(StudyRecords $study): int
    {
        return $study->study['session'] === Protocol::CALIBRATION ? 0 : (int) $study->study['session'];
    }

    private static function settings(RunRecords $records): array
    {
        $inputs = [];
        foreach ($records->attempts as $attempt) {
            $inputs[$attempt['input']['source_id']] = $attempt['input']['status'] === 'available' ? $attempt['input']['sha256'] : null;
        }
        ksort($inputs);

        return [
            'scenario' => $records->run['scenario_id'],
            'source configuration' => $records->run['source_configuration'],
            'expected rejections' => $records->run['expected_rejections'] ?? null,
            'analyzers' => $records->run['analyzers'],
            'resource limits' => $records->run['environment']['resource_limits'] ?? null,
            'container image' => $records->run['environment']['container_image'],
            'CPU model' => $records->run['environment']['cpu_model'] ?? null,
            'inputs' => $inputs,
        ];
    }

    private static function firstLabel(StudyRecords $study, string $implementationId): string
    {
        foreach ($study->study['runs'] as $planned) {
            if ($planned['implementation_id'] === $implementationId) {
                return $planned['label'];
            }
        }

        throw new RuntimeException("The study {$study->study['id']} has no run of {$implementationId}");
    }

    /**
     * @return array<string, array{attempt: array, verdicts: list<string>, metrics: array<string, float>|null}>
     */
    private static function index(RunRecords $records): array
    {
        $verdicts = [];
        foreach ($records->observations as $observation) {
            $verdicts[$observation['attempt_id']][] = $observation['verdict'];
        }
        $metrics = [];
        foreach ($records->measurements ?? [] as $measurement) {
            $metrics[$measurement['attempt_id']] = self::metrics($measurement);
        }

        $index = [];
        foreach ($records->attempts as $attempt) {
            $index[$attempt['input']['source_id']] = ['attempt' => $attempt, 'verdicts' => $verdicts[$attempt['id']] ?? [], 'metrics' => $metrics[$attempt['id']] ?? null];
        }

        return $index;
    }

    /**
     * @return array<string, float>|null
     */
    private static function metrics(array $measurement): ?array
    {
        $values = [];
        foreach ($measurement['metrics'] as $name => $metric) {
            if ($metric['status'] !== 'available') {
                return null;
            }
            $values[$name] = (float) $metric['value'];
        }

        return [
            'wall_time_seconds' => $values['wall_time_seconds'],
            'cpu_seconds' => $values['cpu_user_seconds'] + $values['cpu_system_seconds'],
            'peak_memory_bytes' => $values['peak_memory_bytes'],
        ];
    }

    /**
     * @param  array{attempt: array, verdicts: list<string>, metrics: array<string, float>|null}  $entry
     */
    private static function exclusion(array $entry): ?string
    {
        return match (true) {
            $entry['attempt']['input']['status'] !== 'available' => 'input_unavailable',
            $entry['attempt']['capability'] !== 'supported' => 'not_attempted',
            $entry['attempt']['execution']['outcome'] !== 'succeeded' => 'execution_not_succeeded',
            ! self::validOutput($entry) => 'output_not_valid',
            $entry['metrics'] === null => 'measurement_unavailable',
            default => null,
        };
    }

    /**
     * @param  array{attempt: array, verdicts: list<string>, metrics: array<string, float>|null}  $entry
     */
    private static function validOutput(array $entry): bool
    {
        return $entry['attempt']['execution']['outcome'] === 'succeeded'
            && $entry['verdicts'] !== []
            && array_diff($entry['verdicts'], self::ACCEPTED_VERDICTS) === [];
    }

    /**
     * @param  list<string>  $sourceIds
     * @param  list<array<string, array{attempt: array, verdicts: list<string>, metrics: array<string, float>|null}>>  $runIndexes
     */
    private static function coverage(array $sourceIds, array $runIndexes): array
    {
        $outcomes = array_fill_keys(self::OUTCOMES, 0);
        $validOutputs = 0;
        $inputs = ['valid_in_every_run' => 0, 'valid_in_some_runs' => 0, 'never_valid' => 0];
        foreach ($sourceIds as $sourceId) {
            $valid = 0;
            foreach ($runIndexes as $index) {
                $outcomes[$index[$sourceId]['attempt']['execution']['outcome']]++;
                if (self::validOutput($index[$sourceId])) {
                    $valid++;
                }
            }
            $validOutputs += $valid;
            $inputs[match (true) {
                $valid === count($runIndexes) => 'valid_in_every_run',
                $valid > 0 => 'valid_in_some_runs',
                default => 'never_valid',
            }]++;
        }

        return [
            'runs' => count($runIndexes),
            'attempts' => count($sourceIds) * count($runIndexes),
            'outcomes' => $outcomes,
            'valid_outputs' => $validOutputs,
            'inputs' => count($sourceIds),
            'inputs_by_validity' => $inputs,
        ];
    }

    /**
     * @param  array<string, list<array<string, array{attempt: array, verdicts: list<string>, metrics: array<string, float>|null}>>>  $indexes
     * @param  array<string, array<string, array{attempt: array, verdicts: list<string>, metrics: array<string, float>|null}>>  $indexesByLabel
     * @param  list<string>  $eligible
     */
    private static function session(StudyRecords $study, array $indexes, array $indexesByLabel, array $eligible, string $first, string $second): array
    {
        $protocol = $study->protocol->document;
        $randomizer = new Randomizer(new Mt19937($study->study['seed']));

        $metrics = [];
        foreach (self::METRICS as $metric) {
            $documentMedians = [];
            $dispersion = [];
            foreach ([$first, $second] as $implementationId) {
                $variations = [];
                foreach ($eligible as $sourceId) {
                    $values = array_map(static fn (array $index): float => $index[$sourceId]['metrics'][$metric] ?? throw new RuntimeException("The measurement of {$sourceId} is missing"), $indexes[$implementationId]);
                    $documentMedians[$implementationId][$sourceId] = Statistics::median($values);
                    $variation = Statistics::coefficientOfVariation($values);
                    if ($variation !== null) {
                        $variations[] = $variation;
                    }
                }
                $dispersion[$implementationId] = $variations === [] ? null : ['median' => Statistics::median($variations), 'maximum' => max($variations)];
            }

            $metrics[$metric] = [
                'implementations' => null,
                'ratio' => null,
                'difference' => null,
                'dispersion' => $dispersion,
            ];
            if ($eligible === []) {
                continue;
            }
            foreach ([$first, $second] as $implementationId) {
                $metrics[$metric]['implementations'][$implementationId] = self::distribution(array_values($documentMedians[$implementationId]), $protocol, $randomizer);
            }
            $ratios = [];
            $differences = [];
            $firstLower = 0;
            foreach ($eligible as $sourceId) {
                $differences[] = $documentMedians[$first][$sourceId] - $documentMedians[$second][$sourceId];
                if ($documentMedians[$second][$sourceId] > 0.0) {
                    $ratios[] = $documentMedians[$first][$sourceId] / $documentMedians[$second][$sourceId];
                }
                if ($documentMedians[$first][$sourceId] < $documentMedians[$second][$sourceId]) {
                    $firstLower++;
                }
            }
            $metrics[$metric]['ratio'] = $ratios === [] ? null : ['documents' => count($ratios)] + self::distribution($ratios, $protocol, $randomizer);
            $metrics[$metric]['difference'] = self::distribution($differences, $protocol, $randomizer) + ['first_lower' => $firstLower, 'documents' => count($differences)];
        }

        $references = [];
        foreach ([$first, $second] as $implementationId) {
            $references[$implementationId] = self::reference($study, $implementationId, $protocol);
        }

        $runs = [];
        foreach ($study->study['runs'] as $planned) {
            $records = $study->runs[$planned['label']];
            $index = $indexesByLabel[$planned['label']];
            $runMedians = [];
            foreach (self::METRICS as $metric) {
                $values = [];
                foreach ($eligible as $sourceId) {
                    if (isset($index[$sourceId]['metrics'])) {
                        $values[] = $index[$sourceId]['metrics'][$metric];
                    }
                }
                $runMedians[$metric] = $values === [] ? null : Statistics::median($values);
            }
            $controls = $study->controls[$planned['label']];
            $runs[] = [
                'label' => $planned['label'],
                'implementation_id' => $planned['implementation_id'],
                'role' => $planned['role'],
                'run_id' => $records->run['id'],
                'started_at' => $records->run['started_at'],
                'finished_at' => $records->run['finished_at'],
                'medians' => $runMedians,
                'controls' => [
                    'cpuset' => $controls['cpuset']['status'] === 'available' ? $controls['cpuset']['cpus'] : null,
                    'scaling_governors' => array_values(array_unique(array_values($controls['scaling_governors']))),
                    'boost' => $controls['boost'],
                    'load_average' => $controls['load_average'],
                    'violations' => $controls['violations'],
                ],
            ];
        }

        return [
            'study_id' => $study->study['id'],
            'session' => $study->study['session'],
            'seed' => $study->study['seed'],
            'measured_runs' => [$first => count($indexes[$first]), $second => count($indexes[$second])],
            'metrics' => $metrics,
            'references' => $references,
            'runs' => $runs,
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private static function distribution(array $values, array $protocol, Randomizer $randomizer): array
    {
        return [
            'median' => Statistics::median($values),
            'interval' => Statistics::bootstrapMedianInterval($values, $protocol['bootstrap']['resamples'], $protocol['bootstrap']['confidence'], $randomizer),
            'quantiles' => array_map(static fn (float|int $probability): array => ['probability' => $probability, 'value' => Statistics::quantile($values, (float) $probability)], $protocol['quantiles']),
        ];
    }

    private static function reference(StudyRecords $study, string $implementationId, array $protocol): array
    {
        $attempts = 0;
        $valid = 0;
        $values = array_fill_keys(self::METRICS, []);
        foreach ($study->study['runs'] as $planned) {
            if ($planned['implementation_id'] !== $implementationId || ! in_array($planned['role'], self::MEASURED_ROLES, true)) {
                continue;
            }
            $reference = $study->references[$planned['label']];
            $verdicts = [];
            foreach ($reference['observations'] as $observation) {
                $verdicts[$observation['attempt_id']][] = $observation['verdict'];
            }
            $metrics = [];
            foreach ($reference['measurements'] as $measurement) {
                $metrics[$measurement['attempt_id']] = self::metrics($measurement);
            }
            foreach ($reference['attempts'] as $attempt) {
                $attempts++;
                $entry = ['attempt' => $attempt, 'verdicts' => $verdicts[$attempt['id']] ?? [], 'metrics' => $metrics[$attempt['id']] ?? null];
                if (! self::validOutput($entry) || $entry['metrics'] === null) {
                    continue;
                }
                $valid++;
                foreach (self::METRICS as $metric) {
                    $values[$metric][] = $entry['metrics'][$metric];
                }
            }
        }

        $distributions = [];
        foreach (self::METRICS as $metric) {
            $distributions[$metric] = $values[$metric] === [] ? null : [
                'median' => Statistics::median($values[$metric]),
                'quantiles' => array_map(static fn (float|int $probability): array => ['probability' => $probability, 'value' => Statistics::quantile($values[$metric], (float) $probability)], $protocol['quantiles']),
            ];
        }

        return ['attempts' => $attempts, 'valid_and_measured' => $valid, 'metrics' => $distributions];
    }

    private static function repeatability(array $earlier, array $later, float $tolerance, string $first, string $second): array
    {
        $items = [];
        foreach (self::METRICS as $metric) {
            $statistics = [
                "median of {$first}" => [$earlier['metrics'][$metric]['implementations'][$first]['median'] ?? null, $later['metrics'][$metric]['implementations'][$first]['median'] ?? null],
                "median of {$second}" => [$earlier['metrics'][$metric]['implementations'][$second]['median'] ?? null, $later['metrics'][$metric]['implementations'][$second]['median'] ?? null],
                'median paired ratio' => [$earlier['metrics'][$metric]['ratio']['median'] ?? null, $later['metrics'][$metric]['ratio']['median'] ?? null],
            ];
            foreach ($statistics as $statistic => [$earlierValue, $laterValue]) {
                $change = $earlierValue === null || $laterValue === null || $earlierValue <= 0.0 ? null : $laterValue / $earlierValue - 1;
                $items[] = ['metric' => $metric, 'statistic' => $statistic, 'earlier' => $earlierValue, 'later' => $laterValue, 'relative_change' => $change, 'within_tolerance' => $change !== null && abs($change) <= $tolerance];
            }
        }

        return [
            'tolerance' => $tolerance,
            'within_tolerance' => $items !== [] && ! in_array(false, array_column($items, 'within_tolerance'), true),
            'items' => $items,
        ];
    }
}
