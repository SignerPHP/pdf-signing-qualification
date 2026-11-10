<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Regression;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class RegressionReplay
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/regressions.schema.json';

    public const string SOURCE_ID = 'regression';

    private const string GENERATOR_NAMESPACE = 'SignerPHP\\PdfSigningQualification\\Tests\\Fixtures\\Synthetic\\';

    private const string REPOSITORY_URL = 'https://github.com/SignerPHP/pdf-signing-qualification';

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly AttemptRunner $attemptRunner,
        private readonly ValidationPanel $panel,
        private readonly RegressionConditions $conditions,
        private readonly string $inputDirectory,
        private readonly Closure $clock,
    ) {}

    /**
     * @param  list<RegressionCase>  $cases
     * @param  array<string, array{attempt: array, input_path: ?string, output_path: ?string}>  $runAttempts
     */
    public function replay(string $runId, array $scenario, SigningImplementation $implementation, array $cases, array $runAttempts): array
    {
        $attempts = [];
        $observations = [];
        $results = [];
        foreach ($cases as $case) {
            $condition = $this->conditions->for($case->id);
            $result = ['case_id' => $case->id, 'scenario_id' => $case->scenarioId, 'definition' => $case->definition];

            if ($case->scenarioId !== $scenario['id']) {
                $results[] = $result + ['attempt_id' => null, 'condition' => self::notEvaluated("The case runs under scenario {$case->scenarioId}, and this run is of scenario {$scenario['id']}")];

                continue;
            }

            if ($case->input['kind'] === 'upstream') {
                $sourceId = $case->input['source_id'];
                $runAttempt = $runAttempts[$sourceId] ?? null;
                if ($runAttempt === null) {
                    $results[] = $result + ['attempt_id' => null, 'condition' => self::notEvaluated("The input {$sourceId} is not declared in the source configuration of this run")];

                    continue;
                }
            } else {
                $sourceId = self::SOURCE_ID.':'.$case->id.'.pdf';
                if (isset($runAttempts[$sourceId])) {
                    throw new RuntimeException("The regression case {$case->id} would replay {$sourceId}, which is also a declared input of the run");
                }
                $runAttempt = $this->replaySynthetic($runId, $scenario, $implementation, $case, $sourceId);
                $attempts[] = $runAttempt['attempt'];
                foreach ($runAttempt['observations'] as $observation) {
                    $observations[] = $observation;
                }
            }

            $results[] = $result + ['attempt_id' => $runAttempt['attempt']['id'], 'condition' => self::condition($condition, $runAttempt)];
        }

        $document = ['schema' => self::SCHEMA, 'run_id' => $runId, 'attempts' => $attempts, 'observations' => $observations, 'cases' => $results];
        (new SchemaValidator)->assertValid($document, self::SCHEMA, 'regression replay');

        return $document;
    }

    /**
     * @return array{attempt: array, input_path: ?string, output_path: ?string, observations: list<array>}
     */
    private function replaySynthetic(string $runId, array $scenario, SigningImplementation $implementation, RegressionCase $case, string $sourceId): array
    {
        /** @var array{kind: 'synthetic', generator: string, sha256: string, byte_size: int} $input */
        $input = $case->input;
        $inputPath = $this->generate($case->id, $input['generator']);
        $artifact = Artifact::describe($inputPath);
        $declared = ['sha256' => $input['sha256'], 'byte_size' => $input['byte_size']];
        $available = $artifact === $declared;

        $acquisition = $this->acquisition($sourceId, $case->id, $input['generator'], $declared, $artifact);
        $result = $this->attemptRunner->run($runId, $scenario, $implementation, $acquisition, $available ? $inputPath : null);

        $observations = [];
        $inputArtifact = $available ? $declared : null;
        foreach ($this->panel->observe(new ValidationSubject($result->record['id'], $inputArtifact, $result->outputPath, $available ? $inputPath : null)) as $observation) {
            (new SchemaValidator)->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, "observation {$observation['id']}");
            $observations[] = $observation;
        }

        return ['attempt' => $result->record, 'input_path' => $available ? $inputPath : null, 'output_path' => $result->outputPath, 'observations' => $observations];
    }

    private function generate(string $caseId, string $generator): string
    {
        $class = self::GENERATOR_NAMESPACE.basename($generator, '.php');
        if (! class_exists($class) || ! method_exists($class, 'create')) {
            throw new RuntimeException("The generator {$generator} of the regression case {$caseId} does not define {$class}::create()");
        }

        $directory = $this->inputDirectory.'/'.$caseId;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException("Unable to create {$directory}");
        }
        $path = $class::create($directory);
        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException("The generator {$generator} of the regression case {$caseId} did not write a file");
        }

        return $path;
    }

    /**
     * @param  array{sha256: string, byte_size: int}  $declared
     * @param  array{sha256: string, byte_size: int}  $generated
     */
    private function acquisition(string $sourceId, string $caseId, string $generator, array $declared, array $generated): array
    {
        $license = ['expression' => 'MIT', 'evidence_url' => self::REPOSITORY_URL.'/blob/main/LICENSE'];
        $record = [
            'schema' => Acquirer::RECORD_SCHEMA,
            'source_id' => $sourceId,
            'source' => ['id' => self::SOURCE_ID, 'url' => self::REPOSITORY_URL, 'revision' => ['kind' => 'version', 'value' => '1'], 'license' => $license],
            'path' => $caseId.'.pdf',
            'file_url' => self::REPOSITORY_URL.'/blob/main/'.$generator,
        ];
        if ($generated === $declared) {
            return $record + ['status' => 'available', 'retrieved_at' => $this->now(), 'sha256' => $declared['sha256'], 'byte_size' => $declared['byte_size'], 'license' => $license];
        }

        return $record + [
            'status' => 'unavailable',
            'attempted_at' => $this->now(),
            'reason' => 'hash_mismatch',
            'expected_sha256' => $declared['sha256'],
            'expected_byte_size' => $declared['byte_size'],
            'observed_sha256' => $generated['sha256'],
            'observed_byte_size' => $generated['byte_size'],
            'message' => "{$generator} wrote {$generated['byte_size']} bytes with SHA-256 {$generated['sha256']}, not the input declared by the regression case {$caseId}",
        ];
    }

    /**
     * @param  array{attempt: array, input_path: ?string, output_path: ?string, observations?: list<array>}  $runAttempt
     * @return array{status: string, evidence: string}
     */
    private static function condition(RegressionCondition $condition, array $runAttempt): array
    {
        $attempt = $runAttempt['attempt'];
        if ($attempt['input']['status'] !== 'available') {
            return self::notEvaluated("The input is unavailable ({$attempt['input']['reason']}): {$attempt['input']['message']}");
        }
        if ($attempt['capability'] === 'unsupported') {
            return self::notEvaluated("{$attempt['implementation']['id']} does not support the scenario {$attempt['scenario_id']}, so the input was not signed");
        }
        if ($attempt['execution']['outcome'] !== 'succeeded' || $runAttempt['input_path'] === null || $runAttempt['output_path'] === null) {
            return self::notEvaluated("The attempt ended as {$attempt['execution']['outcome']}, so there is no output to check");
        }

        return $condition->evaluate($runAttempt['input_path'], $runAttempt['output_path']);
    }

    /**
     * @return array{status: 'not_evaluated', evidence: string}
     */
    private static function notEvaluated(string $evidence): array
    {
        return ['status' => 'not_evaluated', 'evidence' => $evidence];
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
