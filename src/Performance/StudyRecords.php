<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use JsonException;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Run\RunRecords;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;

final readonly class StudyRecords
{
    /**
     * @param  array<string, RunRecords>  $runs
     * @param  array<string, array>  $controls
     * @param  array<string, array{attempts: list<array>, measurements: list<array>, observations: list<array>}>  $references
     */
    public function __construct(
        public array $study,
        public Protocol $protocol,
        public array $runs,
        public array $controls,
        public array $references,
    ) {}

    public static function fromDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/');
        $validator = new SchemaValidator;
        $study = self::readJson($directory.'/study.json');
        $validator->assertValid($study, Protocol::STUDY_SCHEMA, "performance study in {$directory}");
        $protocol = Protocol::fromFile($directory.'/protocol.json');
        if ($study['protocol'] !== ['sha256' => hash('sha256', $protocol->contents), 'byte_size' => strlen($protocol->contents)]) {
            throw new RuntimeException("The protocol.json in {$directory} is not the protocol its study.json was planned with");
        }

        $runs = [];
        $controls = [];
        $references = [];
        foreach ($study['runs'] as $planned) {
            $label = $planned['label'];
            $runDirectories = glob($directory.'/run-*-'.$label, GLOB_ONLYDIR) ?: [];
            if (count($runDirectories) !== 1) {
                throw new RuntimeException("The study in {$directory} has ".count($runDirectories)." run directories for {$label}, not one, so the session is not complete");
            }
            $records = RunRecords::fromDirectory($runDirectories[0]);
            if ($records->run['implementation']['id'] !== $planned['implementation_id']) {
                throw new RuntimeException("The run {$records->run['id']} is of {$records->run['implementation']['id']}, but the study planned {$planned['implementation_id']} for {$label}");
            }
            if ($records->measurements === null) {
                throw new RuntimeException("The run {$records->run['id']} has no measurements.json");
            }
            $verdicts = array_map(static fn (array $observation): array => ['attempt_id' => $observation['attempt_id'], 'verdict' => $observation['verdict']], $records->observations);
            $runs[$label] = new RunRecords($records->run, $records->attempts, $verdicts, measurements: $records->measurements);
            unset($records, $verdicts);

            $runControls = self::readJson("{$directory}/controls/{$label}.json");
            $validator->assertValid($runControls, HostControls::SCHEMA, "host controls of {$label} in {$directory}");
            if ($runControls['label'] !== $label) {
                throw new RuntimeException("The host controls in {$directory}/controls/{$label}.json are labelled {$runControls['label']}");
            }
            $controls[$label] = $runControls;

            $references[$label] = self::reference("{$directory}/references/{$label}", $planned['implementation_id']);
        }

        return new self($study, $protocol, $runs, $controls, $references);
    }

    /**
     * @return array{attempts: list<array>, measurements: list<array>, observations: list<array>}
     */
    private static function reference(string $directory, string $implementationId): array
    {
        $validator = new SchemaValidator;
        $attempts = array_values(self::readJson($directory.'/attempts.json'));
        $measurements = array_values(self::readJson($directory.'/measurements.json'));
        $observations = array_values(self::readJson($directory.'/observations.json'));
        $attemptIds = [];
        foreach ($attempts as $attempt) {
            $validator->assertValid((array) $attempt, AttemptRunner::ATTEMPT_SCHEMA, "reference attempt in {$directory}");
            if ($attempt['implementation']['id'] !== $implementationId || ! str_starts_with($attempt['input']['source_id'], FixedCostReference::SOURCE_ID.':')) {
                throw new RuntimeException("The reference attempt {$attempt['id']} in {$directory} is not of {$implementationId} on the reference document");
            }
            $attemptIds[$attempt['id']] = $attempt['execution']['outcome'];
        }
        foreach ($measurements as $measurement) {
            $validator->assertValid((array) $measurement, AttemptRunner::MEASUREMENT_SCHEMA, "reference measurement in {$directory}");
            if (($attemptIds[$measurement['attempt_id']] ?? null) !== $measurement['execution_outcome']) {
                throw new RuntimeException("The reference measurement of {$measurement['attempt_id']} in {$directory} does not match a reference attempt and its outcome");
            }
        }
        foreach ($observations as $observation) {
            $validator->assertValid((array) $observation, ToolValidator::OBSERVATION_SCHEMA, "reference observation in {$directory}");
            if (! isset($attemptIds[$observation['attempt_id']])) {
                throw new RuntimeException("The reference observation {$observation['id']} in {$directory} is not of a reference attempt");
            }
        }

        /** @var array{attempts: list<array>, measurements: list<array>, observations: list<array>} */
        return ['attempts' => $attempts, 'measurements' => $measurements, 'observations' => $observations];
    }

    private static function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read {$path}");
        }
        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$path} is not valid JSON: {$exception->getMessage()}");
        }
        if (! is_array($document)) {
            throw new RuntimeException("{$path} does not hold a JSON object or array");
        }

        return $document;
    }
}
