<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

final class SigningRequest
{
    /**
     * @param  array{digest_algorithm: string, signature_algorithm: string, sub_filter: string, appearance: string, placement?: array{page: int, left: int|float, top: int|float, width: int|float, height: int|float}, timestamp: bool, revocation_info: bool, document_timestamp?: bool, signers?: list<string>, certification_level?: int, signature_policy?: string}  $signing
     * @param  list<TestCredential>  $additionalCredentials
     */
    public function __construct(
        public readonly string $inputPath,
        public readonly string $outputPath,
        public readonly TestCredential $credential,
        public readonly array $signing,
        public readonly array $additionalCredentials = [],
        public readonly ?string $timestampUrl = null,
    ) {}
}
