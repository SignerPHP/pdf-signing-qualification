<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Run;

use InvalidArgumentException;
use SignerPHP\PdfSigningQualification\Acquisition\Input;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final readonly class ExpectedRejections
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/expected-rejections.schema.json';

    /**
     * @param  array<string, array<string, true>>  $sourceIdsByScenario
     * @param  array{sha256: string, byte_size: int}  $artifact
     */
    private function __construct(
        private array $sourceIdsByScenario,
        public array $artifact,
    ) {}

    /**
     * @param  list<Input>  $inputs
     */
    public static function fromFile(string $path, array $inputs, string $scenarioDirectory): self
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Unable to read the expected rejections {$path}");
        }

        $errors = (new SchemaValidator)->errors((object) json_decode($contents, flags: JSON_THROW_ON_ERROR), self::SCHEMA);
        if ($errors !== []) {
            throw new InvalidArgumentException("The expected rejections do not follow their schema:\n".implode("\n", $errors));
        }

        /** @var array{rejections: list<array{source_id: string, scenario_id: string, reason: string}>} $configuration */
        $configuration = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $sourceIds = array_map(static fn (Input $input): string => $input->id(), $inputs);
        $scenarioIds = self::scenarioIds($scenarioDirectory);
        $sourceIdsByScenario = [];
        foreach ($configuration['rejections'] as $rejection) {
            $sourceId = $rejection['source_id'];
            $scenarioId = $rejection['scenario_id'];
            if (! in_array($sourceId, $sourceIds, true)) {
                throw new InvalidArgumentException("An expected rejection refers to the undeclared input {$sourceId}");
            }
            if (! in_array($scenarioId, $scenarioIds, true)) {
                throw new InvalidArgumentException("The expected rejection of {$sourceId} refers to the undeclared scenario {$scenarioId}");
            }
            if (isset($sourceIdsByScenario[$scenarioId][$sourceId])) {
                throw new InvalidArgumentException("The expected rejection of {$sourceId} under {$scenarioId} is declared more than once");
            }
            $sourceIdsByScenario[$scenarioId][$sourceId] = true;
        }

        return new self($sourceIdsByScenario, Artifact::describe($path));
    }

    public function declares(string $sourceId, string $scenarioId): bool
    {
        return isset($this->sourceIdsByScenario[$scenarioId][$sourceId]);
    }

    /**
     * @return list<string>
     */
    private static function scenarioIds(string $scenarioDirectory): array
    {
        $scenarioIds = [];
        foreach (glob($scenarioDirectory.'/*.json') ?: [] as $scenarioPath) {
            /** @var array{id: string} $scenario */
            $scenario = json_decode((string) file_get_contents($scenarioPath), true, flags: JSON_THROW_ON_ERROR);
            $scenarioIds[] = $scenario['id'];
        }

        return $scenarioIds;
    }
}
