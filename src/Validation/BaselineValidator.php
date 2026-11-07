<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

interface BaselineValidator extends Validator
{
    public function baseline(BaselineSubject $subject): array;
}
