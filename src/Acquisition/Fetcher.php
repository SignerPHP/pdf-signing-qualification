<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Acquisition;

interface Fetcher
{
    public function fetch(string $url): string;
}
