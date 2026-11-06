<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Signing;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Signing\Implementations;

final class ImplementationsTest extends TestCase
{
    public function test_implementation_is_selected_by_its_identifier(): void
    {
        self::assertSame('pdf-signer', Implementations::select('pdf-signer')->id());
        self::assertSame('jsignpdf', Implementations::select('jsignpdf')->id());
    }

    public function test_implementation_without_a_runtime_of_its_own_reports_none(): void
    {
        self::assertSame([], Implementations::select('pdf-signer')->runtime());
    }

    public function test_unknown_identifier_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown implementation unknown-signer; available: pdf-signer, jsignpdf');

        Implementations::select('unknown-signer');
    }
}
