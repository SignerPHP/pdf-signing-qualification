<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Acquisition\Acquirer;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Validation\CommandLineValidator;
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
        private readonly string $cacheDirectory,
        private readonly Closure $clock,
    ) {}

    /**
     * @param  list<Input>  $inputs
     * @param  list<array>  $acquisitions
     * @param  array{sha256: string, byte_size: int}  $sourceConfiguration
     * @return array{run: array, attempts: list<array>, observations: list<array>}
     */
    public function run(string $runId, array $scenario, array $inputs, array $acquisitions, array $environment, array $sourceConfiguration): array
    {
        $acquiredInputs = self::acquiredInputs($inputs, $acquisitions);
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
        ];

        $attempts = [];
        $observations = [];
        foreach ($acquiredInputs as [$input, $acquisition]) {
            $inputPath = $acquisition['status'] === 'available' ? Acquirer::filePath($this->cacheDirectory, $input) : null;
            $result = $this->attemptRunner->run($runId, $scenario, $this->implementation, $acquisition, $inputPath);
            $attempts[] = $result->record;

            $inputArtifact = $result->record['input']['status'] === 'available' ? ['sha256' => $result->record['input']['sha256'], 'byte_size' => $result->record['input']['byte_size']] : null;
            foreach ($this->panel->observe(new ValidationSubject($result->record['id'], $inputArtifact, $result->outputPath)) as $observation) {
                (new SchemaValidator)->assertValid($observation, CommandLineValidator::OBSERVATION_SCHEMA, "observation {$observation['id']}");
                $observations[] = $observation;
            }
        }

        $run['finished_at'] = $this->now();
        (new SchemaValidator)->assertValid($run, self::RUN_SCHEMA, 'run record');

        return ['run' => $run, 'attempts' => $attempts, 'observations' => $observations];
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
