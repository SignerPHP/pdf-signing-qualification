<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

use InvalidArgumentException;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

final class SourceConfiguration
{
    public const string SCHEMA = SchemaValidator::SCHEMA_PREFIX.'v1/sources.schema.json';

    /**
     * @return list<Input>
     */
    public static function fromFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new InvalidArgumentException("Unable to read the source configuration {$path}");
        }

        self::assertFollowsSchema(json_decode($contents, flags: JSON_THROW_ON_ERROR));

        /** @var array $configuration */
        $configuration = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return self::bind($configuration);
    }

    /**
     * @return list<Input>
     */
    public static function fromArray(array $configuration): array
    {
        self::assertFollowsSchema($configuration);

        return self::bind($configuration);
    }

    private static function assertFollowsSchema(mixed $configuration): void
    {
        $errors = is_array($configuration) || is_object($configuration)
            ? (new SchemaValidator)->errors($configuration, self::SCHEMA)
            : ['/: The source configuration is not a JSON object'];
        if ($errors !== []) {
            throw new InvalidArgumentException("The source configuration does not follow its schema:\n".implode("\n", $errors));
        }
    }

    /**
     * @return list<Input>
     */
    private static function bind(array $configuration): array
    {
        /** @var array<string, Source> $sources */
        $sources = [];
        foreach ($configuration['sources'] as $source) {
            if (isset($sources[$source['id']])) {
                throw new InvalidArgumentException("Source {$source['id']} is declared more than once");
            }
            $sources[$source['id']] = new Source($source['id'], $source['url'], $source['revision'], $source['file_url'], $source['license']);
        }

        $inputs = [];
        foreach ($configuration['inputs'] as $declaration) {
            $source = $sources[$declaration['source']]
                ?? throw new InvalidArgumentException("Input {$declaration['path']} refers to the undeclared source {$declaration['source']}");
            $input = new Input($source, $declaration['path'], $declaration['sha256'], $declaration['byte_size'], $declaration['license']);
            if (isset($inputs[$input->id()])) {
                throw new InvalidArgumentException("Input {$input->id()} is declared more than once");
            }
            $inputs[$input->id()] = $input;
        }

        return array_values($inputs);
    }
}
