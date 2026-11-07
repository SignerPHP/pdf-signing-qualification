<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Panel;

use SignerPHP\PdfSigningQualification\Acquisition\Source;

final readonly class PlanSource
{
    /**
     * @param  array{expression: string, evidence_url: string}  $documentLicense
     * @param  list<string>  $include
     * @param  list<array{pattern: string, reason: string}>  $exclude
     */
    public function __construct(
        public Source $source,
        public string $listingUrlTemplate,
        public array $documentLicense,
        public array $include,
        public array $exclude,
    ) {}

    public function listingUrl(): string
    {
        return strtr($this->listingUrlTemplate, ['{revision}' => rawurlencode($this->source->revision['value'])]);
    }

    public function isIncluded(string $path): bool
    {
        foreach ($this->include as $pattern) {
            if (self::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{pattern: string, reason: string}|null
     */
    public function exclusion(string $path): ?array
    {
        foreach ($this->exclude as $exclusion) {
            if (self::matches($exclusion['pattern'], $path)) {
                return $exclusion;
            }
        }

        return null;
    }

    private static function matches(string $pattern, string $path): bool
    {
        return fnmatch($pattern, $path, FNM_CASEFOLD);
    }
}
