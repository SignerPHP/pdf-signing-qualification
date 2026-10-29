<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Signing;

use Closure;
use SignerPHP\PdfSigningQualification\Signing\SigningImplementation;
use SignerPHP\PdfSigningQualification\Signing\SigningRequest;

final class CommandImplementation implements SigningImplementation
{
    /**
     * @param  Closure(SigningRequest): list<string>  $command
     */
    public function __construct(
        private readonly Closure $command,
        private readonly bool $supported = true,
    ) {}

    public function id(): string
    {
        return 'command-signer';
    }

    public function revision(): array
    {
        return ['kind' => 'version', 'value' => '1.0.0'];
    }

    public function supports(array $signing): bool
    {
        return $this->supported;
    }

    public function command(SigningRequest $request): array
    {
        return ($this->command)($request);
    }
}
