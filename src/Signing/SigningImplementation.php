<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Signing;

interface SigningImplementation
{
    public function id(): string;

    /**
     * @return array{kind: 'commit'|'version', value: string}
     */
    public function revision(): array;

    /**
     * @param  array{digest_algorithm: string, signature_algorithm: string, sub_filter: string, appearance: string, timestamp: bool, revocation_info: bool}  $signing
     */
    public function supports(array $signing): bool;

    /**
     * @return list<string>
     */
    public function command(SigningRequest $request): array;
}
