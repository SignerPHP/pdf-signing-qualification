<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

interface Validator
{
    public function observe(ValidationSubject $subject): array;
}
