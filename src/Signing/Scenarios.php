<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class Scenarios
{
    public const array SCHEMAS = [
        SchemaValidator::SCHEMA_PREFIX.'v1/scenario.schema.json',
        SchemaValidator::SCHEMA_PREFIX.'v2/scenario.schema.json',
    ];

    public const string DEFAULT_ID = 'basic-invisible-sha256';

    private const string ID_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @return list<string>
     */
    public static function errors(array|object $scenario): array
    {
        $schemaId = is_array($scenario) ? ($scenario['schema'] ?? null) : ($scenario->schema ?? null);
        if (! in_array($schemaId, self::SCHEMAS, true)) {
            return ['/schema: the scenario must name one of the scenario schemas: '.implode(', ', self::SCHEMAS)];
        }

        return (new SchemaValidator)->errors($scenario, $schemaId);
    }

    public static function assertValid(array $scenario): void
    {
        $errors = self::errors($scenario);
        if ($errors !== []) {
            throw new RuntimeException('Invalid scenario: '.implode('; ', $errors));
        }
    }

    public static function load(string $directory, string $id): array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("{$id} is not a scenario identifier");
        }
        $path = rtrim($directory, '/').'/'.$id.'.json';
        if (! is_file($path)) {
            throw new InvalidArgumentException("The scenario {$id} is not declared in {$directory}");
        }

        return self::read($path);
    }

    /**
     * @return list<string>
     */
    public static function ids(string $directory): array
    {
        $paths = glob(rtrim($directory, '/').'/*.json');
        if ($paths === false) {
            throw new InvalidArgumentException("Unable to list the scenarios in {$directory}");
        }
        sort($paths);

        return array_map(static fn (string $path): string => self::read($path)['id'], $paths);
    }

    private static function read(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Unable to read {$path}");
        }

        try {
            $scenario = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$path} is not valid JSON: {$exception->getMessage()}", previous: $exception);
        }
        if (! is_array($scenario)) {
            throw new InvalidArgumentException("{$path} does not hold a JSON object");
        }

        $errors = self::errors($scenario);
        if ($errors !== []) {
            throw new InvalidArgumentException("The scenario {$path} does not follow its schema:\n".implode("\n", $errors));
        }
        if ($scenario['id'] !== basename($path, '.json')) {
            throw new InvalidArgumentException("The scenario {$path} has the id {$scenario['id']}; name its file {$scenario['id']}.json");
        }

        return $scenario;
    }
}
