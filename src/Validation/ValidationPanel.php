<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class ValidationPanel
{
    /**
     * @param  list<Validator>  $validators
     */
    public function __construct(
        private readonly array $validators,
    ) {}

    /**
     * @return list<array>
     */
    public function observe(ValidationSubject $subject): array
    {
        return array_map(static fn (Validator $validator): array => $validator->observe($subject), $this->validators);
    }
}
