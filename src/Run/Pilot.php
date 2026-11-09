<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Regression\RegressionCase;
use SignerPHP\PdfSigningQualification\Regression\RegressionReplay;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Validation\BaselineSubject;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;
use SignerPHP\PdfSigningQualification\Validation\ValidationPanel;
use SignerPHP\PdfSigningQualification\Validation\ValidationSubject;

final class Pilot
{
    public const string RUN_SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/run.schema.json';

    /**
     * @param  Closure(): DateTimeImmutable  $clock
     */
    public function __construct(
        private readonly AttemptRunner $attemptRunner,
        private readonly SigningImplementation $implementation,
        private readonly ValidationPanel $panel,
        private readonly ExpectedRejections $expectedRejections,
        private readonly string $cacheDirectory,
        private readonly Closure $clock,
        private readonly ?RegressionReplay $regressionReplay = null,
    ) {}

    /**
     * @param  list<Input>  $inputs
     * @param  list<array>  $acquisitions
     * @param  array{sha256: string, byte_size: int}  $sourceConfiguration
     * @param  list<RegressionCase>  $regressionCases
     * @return array{run: array, baselines: list<array>, attempts: list<array>, measurements: list<array>, observations: list<array>, regressions: ?array}
     */
    public function run(string $runId, array $scenario, array $inputs, array $acquisitions, array $environment, array $sourceConfiguration, array $regressionCases = []): array
    {
        if ($regressionCases !== [] && $this->regressionReplay === null) {
            throw new RuntimeException('The run has regression cases but nothing to replay them with');
        }
        $acquiredInputs = self::acquiredInputs($inputs, $acquisitions);
        $this->attemptRunner->assertContained();
        $run = [
            'schema' => self::RUN_SCHEMA,
            'id' => $runId,
            'scenario_id' => $scenario['id'],
            'started_at' => $this->now(),
            'finished_at' => null,
            'environment' => $environment,
            'implementation' => ['id' => $this->implementation->id(), 'revision' => $this->implementation->revision()],
            'analyzers' => $this->panel->analyzers(),
            'source_configuration' => $sourceConfiguration,
            'expected_rejections' => $this->expectedRejections->artifact,
        ];
        $runtime = $this->implementation->runtime();
        if ($runtime !== []) {
            $run['implementation_runtime'] = $runtime;
        }
        $run['implementation_compatibility'] = $this->compatibility();

        $baselines = [];
        $attempts = [];
        $measurements = [];
        $observations = [];
        $runAttempts = [];
        foreach ($acquiredInputs as [$input, $acquisition]) {
            $inputPath = $acquisition['status'] === 'available' ? Acquirer::filePath($this->cacheDirectory, $input) : null;
            if ($inputPath !== null) {
                array_push($baselines, ...$this->baselines($runId, $acquisition, $inputPath));
            }
            $result = $this->attemptRunner->run($runId, $scenario, $this->implementation, $acquisition, $inputPath, $this->expectedRejections->declares($input->id(), $scenario['id']));
            $attempts[] = $result->record;
            if ($result->measurement !== null) {
                $measurements[] = $result->measurement;
            }
            $runAttempts[$input->id()] = ['attempt' => $result->record, 'input_path' => $inputPath, 'output_path' => $result->outputPath];

            $inputArtifact = $result->record['input']['status'] === 'available' ? ['sha256' => $result->record['input']['sha256'], 'byte_size' => $result->record['input']['byte_size']] : null;
            foreach ($this->panel->observe(new ValidationSubject($result->record['id'], $inputArtifact, $result->outputPath, $inputPath)) as $observation) {
                (new SchemaValidator)->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, "observation {$observation['id']}");
                $observations[] = $observation;
            }
        }

        $regressions = $this->regressionReplay?->replay($runId, $scenario, $this->implementation, $regressionCases, $runAttempts);

        $run['finished_at'] = $this->now();
        (new SchemaValidator)->assertValid($run, self::RUN_SCHEMA, 'run record');

        return ['run' => $run, 'baselines' => $baselines, 'attempts' => $attempts, 'measurements' => $measurements, 'observations' => $observations, 'regressions' => $regressions];
    }

    /**
     * @return array{status: 'compatible'|'not_checked'}|array{status: 'incompatible', reason: string}
     */
    private function compatibility(): array
    {
        if ($this->implementation->compatibilityCheck() === null) {
            return ['status' => 'not_checked'];
        }
        $reason = $this->attemptRunner->incompatibility($this->implementation);

        return $reason === null ? ['status' => 'compatible'] : ['status' => 'incompatible', 'reason' => $reason];
    }

    /**
     * @return list<array>
     */
    private function baselines(string $runId, array $acquisition, string $inputPath): array
    {
        $inputArtifact = Artifact::describe($inputPath);
        if ($inputArtifact !== ['sha256' => $acquisition['sha256'], 'byte_size' => $acquisition['byte_size']]) {
            throw new RuntimeException("The input {$inputPath} does not match its acquisition record for {$acquisition['source_id']}");
        }

        $baselines = $this->panel->baseline(new BaselineSubject($runId, $acquisition['source_id'], $inputArtifact, $inputPath));
        foreach ($baselines as $baseline) {
            (new SchemaValidator)->assertValid($baseline, CommandLineValidator::BASELINE_SCHEMA, "baseline {$baseline['id']}");
        }

        return $baselines;
    }

    /**
     * @param  list<Input>  $inputs
     * @param  list<array>  $acquisitions
     * @return list<array{Input, array}>
     */
    private static function acquiredInputs(array $inputs, array $acquisitions): array
    {
        $recordsBySourceId = [];
        foreach (array_values($acquisitions) as $index => $acquisition) {
            $position = $index + 1;
            if (! is_array($acquisition)) {
                throw new RuntimeException("Invalid acquisition record {$position}: it is not an object");
            }
            (new SchemaValidator)->assertValid($acquisition, Acquirer::RECORD_SCHEMA, "acquisition record {$position}");
            $sourceId = $acquisition['source_id'];
            if (isset($recordsBySourceId[$sourceId])) {
                throw new RuntimeException("The input {$sourceId} has more than one acquisition record");
            }
            $recordsBySourceId[$sourceId] = $acquisition;
        }

        $ordered = [];
        foreach ($inputs as $input) {
            $acquisition = $recordsBySourceId[$input->id()] ?? throw new RuntimeException("The declared input {$input->id()} has no acquisition record; acquire every input before the run");
            $recordedSha256 = $acquisition['status'] === 'available' ? $acquisition['sha256'] : $acquisition['expected_sha256'];
            if ($recordedSha256 !== $input->sha256) {
                throw new RuntimeException("The acquisition record of {$input->id()} is for SHA-256 {$recordedSha256}, but the input is declared with {$input->sha256}; acquire it again");
            }
            $ordered[] = [$input, $acquisition];
            unset($recordsBySourceId[$input->id()]);
        }
        if ($recordsBySourceId !== []) {
            throw new RuntimeException('These acquisition records are for undeclared inputs: '.implode(', ', array_keys($recordsBySourceId)));
        }

        return $ordered;
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
