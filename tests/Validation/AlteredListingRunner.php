<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Validation;

use Closure;
use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;
use SignerPHP\PdfSigningQualification\Validation\ProcessRunner;
use SignerPHP\PdfSigningQualification\Validation\SignatureListing;

final class AlteredListingRunner implements CommandRunner
{
    /**
     * @param  Closure(CommandResult): CommandResult  $alter
     */
    public function __construct(
        private readonly string $documentPath,
        private readonly Closure $alter,
    ) {}

    public function run(array $command, float $timeoutSeconds): CommandResult
    {
        $result = (new ProcessRunner)->run($command, $timeoutSeconds);

        return $command === ['qpdf', ...SignatureListing::ARGUMENTS, $this->documentPath] ? ($this->alter)($result) : $result;
    }

    public static function listingOf(string $documentPath): string
    {
        return (new ProcessRunner)->run(['qpdf', ...SignatureListing::ARGUMENTS, $documentPath], 30.0)->output;
    }
}
