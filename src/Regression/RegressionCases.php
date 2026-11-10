<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Regression;

use InvalidArgumentException;
use JsonException;
use SignerPHP\PdfSigningQualification\Artifact;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class RegressionCases
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/regression.schema.json';

    /**
     * @return list<RegressionCase>
     */
    public static function fromDirectory(string $directory, string $scenarioDirectory): array
    {
        $paths = glob(rtrim($directory, '/').'/*.json');
        if ($paths === false) {
            throw new InvalidArgumentException("Unable to list the regression cases in {$directory}");
        }
        sort($paths);

        $cases = [];
        foreach ($paths as $path) {
            $case = self::decode($path);
            $errors = (new SchemaValidator)->errors($case, self::SCHEMA);
            if ($errors !== []) {
                throw new InvalidArgumentException("The regression case {$path} does not follow its schema:\n".implode("\n", $errors));
            }
            if ($case['id'] !== basename($path, '.json')) {
                throw new InvalidArgumentException("The regression case {$path} has the id {$case['id']}; name its file {$case['id']}.json");
            }
            if (! is_file($scenarioDirectory.'/'.$case['scenario_id'].'.json')) {
                throw new InvalidArgumentException("The regression case {$case['id']} refers to the undeclared scenario {$case['scenario_id']}");
            }
            $cases[] = new RegressionCase($case['id'], $case['scenario_id'], $case['input'], Artifact::describe($path));
        }

        return $cases;
    }

    private static function decode(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Unable to read {$path}");
        }

        try {
            $case = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("{$path} is not valid JSON: {$exception->getMessage()}", previous: $exception);
        }
        if (! is_array($case)) {
            throw new InvalidArgumentException("{$path} does not hold a JSON object");
        }

        return $case;
    }
}
