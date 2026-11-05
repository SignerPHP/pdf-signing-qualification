<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

interface Validator
{
    /**
     * @return array{id: string, revision: array{kind: 'version', value: string}}
     */
    public function analyzer(): array;

    public function observe(ValidationSubject $subject): array;
}
