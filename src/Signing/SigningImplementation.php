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
     * @param  array{digest_algorithm: string, signature_algorithm: string, sub_filter: string, appearance: string, placement?: array{page: int, left: int|float, top: int|float, width: int|float, height: int|float}, timestamp: bool, revocation_info: bool, document_timestamp?: bool, signers?: list<string>, certification_level?: int, signature_policy?: string}  $signing
     */
    public function supports(array $signing): bool;

    /**
     * @return array{java_version?: string, java_options?: list<string>, environment_variables?: list<string>, engine?: string, wrapper?: array{id: string, revision: array{kind: 'commit'|'version', value: string}}}
     */
    public function runtime(): array;

    /**
     * @return list<string>
     */
    public function command(SigningRequest $request): array;

    /**
     * @param  array|null  $signing  the signing of the run's scenario, when the options depend on it
     * @return array{command: list<string>, options: list<string>}|null
     */
    public function compatibilityCheck(?array $signing = null): ?array;
}
