<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class AddedSignatures
{
    public const string REPORT_PREFIX = 'qualification-report: ';

    public static function report(CommandResult $result): ?array
    {
        if ($result->exitCode !== 0 || preg_match('/^'.preg_quote(self::REPORT_PREFIX, '/').'(\{.*\})$/m', $result->output, $matches) !== 1) {
            return null;
        }
        $report = json_decode($matches[1], true);

        return is_array($report) && is_array($report['signatures'] ?? null) ? $report : null;
    }

    /**
     * @return list<string>|null
     */
    public static function inputFields(CommandRunner $runner, ?string $inputPath, float $timeoutSeconds): ?array
    {
        if ($inputPath === null) {
            return [];
        }
        $listing = SignatureListing::read($runner, $inputPath, $timeoutSeconds);
        if ($listing->signatures === null || ! $listing->isComplete()) {
            return null;
        }

        $fields = [];
        foreach ($listing->signatures as $signature) {
            if ($signature['field'] !== null) {
                $fields[$signature['field']] = true;
            }
        }

        return array_map(strval(...), array_keys($fields));
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $inputFields
     */
    public static function isPrior(array $fields, array $inputFields): bool
    {
        return array_intersect($fields, $inputFields) !== [];
    }
}
