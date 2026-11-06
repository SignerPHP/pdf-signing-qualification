<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class Judgement
{
    public function __construct(
        public readonly string $verdict,
        public readonly string $summary,
        public readonly ?int $exitCode,
        public readonly string $rawOutput,
        public readonly array $details = [],
        public readonly ?string $inputVerdict = null,
    ) {}

    public static function noSignature(string $summary, ?int $exitCode, string $rawOutput, array $details = [], bool $documentWasRead = true): self
    {
        return new self('invalid', $summary, $exitCode, $rawOutput, $details, $documentWasRead ? 'not_applicable' : 'inconclusive');
    }
}
