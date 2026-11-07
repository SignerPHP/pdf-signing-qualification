<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use JsonException;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;
use SignerPHP\PdfSigningQualification\Signing\AttemptRunner;
use SignerPHP\PdfSigningQualification\Validation\ToolValidator;

final readonly class RunRecords
{
    /**
     * @param  list<array>  $attempts
     * @param  list<array>  $observations
     */
    public function __construct(
        public array $run,
        public array $attempts,
        public array $observations,
    ) {}

    public static function fromDirectory(string $directory): self
    {
        $directory = rtrim($directory, '/');
        if (! is_file($directory.'/run.json')) {
            throw new RuntimeException("{$directory} has no run.json, so it holds an aborted or unfinished run, not results");
        }

        $validator = new SchemaValidator;
        $run = self::readJson($directory.'/run.json');
        $validator->assertValid($run, Pilot::RUN_SCHEMA, "run record in {$directory}");

        $attempts = array_values(self::readJson($directory.'/attempts.json'));
        $attemptsById = [];
        $sourceIds = [];
        foreach ($attempts as $index => $attempt) {
            if (! is_array($attempt)) {
                throw new RuntimeException('Attempt '.($index + 1)." in {$directory} is not an object");
            }
            $validator->assertValid($attempt, AttemptRunner::ATTEMPT_SCHEMA, 'attempt '.(string) ($attempt['id'] ?? $index + 1)." in {$directory}");
            if ($attempt['run_id'] !== $run['id'] || $attempt['scenario_id'] !== $run['scenario_id']) {
                throw new RuntimeException("The attempt {$attempt['id']} in {$directory} does not belong to the run {$run['id']} and its scenario {$run['scenario_id']}");
            }
            $sourceId = $attempt['input']['source_id'];
            if (isset($sourceIds[$sourceId])) {
                throw new RuntimeException("The run in {$directory} has more than one attempt on {$sourceId}");
            }
            $sourceIds[$sourceId] = true;
            $attemptsById[$attempt['id']] = [];
        }

        $analyzerIds = array_column($run['analyzers'], 'id');
        $observations = array_values(self::readJson($directory.'/observations.json'));
        foreach ($observations as $index => $observation) {
            if (! is_array($observation)) {
                throw new RuntimeException('Observation '.($index + 1)." in {$directory} is not an object");
            }
            $validator->assertValid($observation, ToolValidator::OBSERVATION_SCHEMA, 'observation '.(string) ($observation['id'] ?? $index + 1)." in {$directory}");
            $attemptId = $observation['attempt_id'];
            $analyzerId = $observation['analyzer']['id'];
            if (! isset($attemptsById[$attemptId]) || ! in_array($analyzerId, $analyzerIds, true)) {
                throw new RuntimeException("The observation {$observation['id']} in {$directory} is for an attempt or an analyzer that is not in the run");
            }
            if (isset($attemptsById[$attemptId][$analyzerId])) {
                throw new RuntimeException("The attempt {$attemptId} in {$directory} has more than one observation from {$analyzerId}");
            }
            $attemptsById[$attemptId][$analyzerId] = true;
        }
        foreach ($attemptsById as $attemptId => $observedAnalyzers) {
            if (count($observedAnalyzers) !== count($analyzerIds)) {
                throw new RuntimeException("The attempt {$attemptId} in {$directory} does not have one observation from each analyzer of the run");
            }
        }

        return new self($run, $attempts, $observations);
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
