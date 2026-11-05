<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

final class SigningRequest
{
    /**
     * @param  array{digest_algorithm: string, signature_algorithm: string, sub_filter: string, appearance: string, timestamp: bool, revocation_info: bool}  $signing
     */
    public function __construct(
        public readonly string $inputPath,
        public readonly string $outputPath,
        public readonly TestCredential $credential,
        public readonly array $signing,
    ) {}
}
