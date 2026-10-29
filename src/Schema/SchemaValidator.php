<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Schema;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Helper;
use Opis\JsonSchema\Validator;

final class SchemaValidator
{
    public const string SCHEMA_PREFIX = 'https://github.com/SignerPHP/pdf-signing-qualification/blob/main/schemas/';

    public const string SCHEMA_DIRECTORY = __DIR__.'/../../schemas/';

    /**
     * @return list<string>
     */
    public function errors(array|object $document, string $schemaId): array
    {
        $validator = new Validator;
        $validator->setMaxErrors(20);
        $validator->resolver()?->registerPrefix(self::SCHEMA_PREFIX, self::SCHEMA_DIRECTORY);

        $error = $validator->validate(Helper::toJSON($document), $schemaId)->error();
        if ($error === null) {
            return [];
        }

        $messages = [];
        foreach ((new ErrorFormatter)->format($error, true) as $path => $pathMessages) {
            foreach ($pathMessages as $message) {
                $messages[] = $path.': '.$message;
            }
        }

        return $messages;
    }
}
