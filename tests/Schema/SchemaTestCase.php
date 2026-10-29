<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Schema;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Schema\SchemaValidator;

abstract class SchemaTestCase extends TestCase
{
    public const string SCHEMA_PREFIX = SchemaValidator::SCHEMA_PREFIX;

    private const string SCHEMA_DIRECTORY = SchemaValidator::SCHEMA_DIRECTORY;

    abstract protected static function schemaPath(): string;

    abstract protected static function validDocument(): array;

    final protected static function schemaId(): string
    {
        return self::SCHEMA_PREFIX.static::schemaPath();
    }

    /**
     * @return list<string>
     */
    final protected static function validationErrors(array $document): array
    {
        return (new SchemaValidator)->errors($document, self::schemaId());
    }

    final protected static function assertValid(array $document): void
    {
        self::assertSame([], self::validationErrors($document));
    }

    final protected static function assertInvalid(array $document): void
    {
        self::assertNotSame([], self::validationErrors($document), 'The document was accepted by '.self::schemaId());
    }

    /**
     * @param  callable(array): array  $mutation
     */
    final protected static function mutate(callable $mutation, ?array $document = null): array
    {
        return $mutation($document ?? static::validDocument());
    }

    public function test_schema_file_declares_its_versioned_id(): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA_DIRECTORY.static::schemaPath()), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($schema);
        self::assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        self::assertSame(self::schemaId(), $schema['$id']);
        self::assertMatchesRegularExpression('#^v\d+/#', static::schemaPath());
    }

    public function test_valid_document_is_accepted(): void
    {
        self::assertValid(static::validDocument());
    }

    public function test_document_must_declare_the_schema_it_follows(): void
    {
        $document = static::validDocument();
        unset($document['schema']);
        self::assertInvalid($document);

        $document['schema'] = self::SCHEMA_PREFIX.'v0/'.basename(static::schemaPath());
        self::assertInvalid($document);
    }

    public function test_unknown_top_level_field_is_rejected(): void
    {
        self::assertInvalid(static::validDocument() + ['unexpected' => true]);
    }
}
