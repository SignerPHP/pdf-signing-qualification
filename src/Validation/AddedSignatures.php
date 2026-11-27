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

        if (! is_array($report) || ! is_array($report['signatures'] ?? null)) {
            return null;
        }
        foreach ($report['signatures'] as $signature) {
            if (! is_array($signature) || ! array_key_exists('byte_range', $signature)) {
                return null;
            }
        }

        return $report;
    }

    /**
     * @return list<list<int>>|false|null
     */
    public static function priorByteRanges(CommandRunner $runner, ?string $inputPath, float $timeoutSeconds, array $reportedSignatures): array|false|null
    {
        $inputByteRanges = self::inputByteRanges($runner, $inputPath, $timeoutSeconds);
        if (! is_array($inputByteRanges) || $inputByteRanges === []) {
            return $inputByteRanges;
        }
        foreach ($reportedSignatures as $signature) {
            if (! is_array($signature) || ! self::isByteRange($signature['byte_range'] ?? null)) {
                return false;
            }
        }

        return $inputByteRanges;
    }

    /**
     * @return list<list<int>>|false|null
     */
    private static function inputByteRanges(CommandRunner $runner, ?string $inputPath, float $timeoutSeconds): array|false|null
    {
        if ($inputPath === null) {
            return [];
        }
        $listing = SignatureListing::read($runner, $inputPath, $timeoutSeconds);
        if ($listing->signatures === null || ! $listing->isComplete()) {
            return null;
        }

        $byteRanges = [];
        foreach ($listing->signatures as $signature) {
            if ($signature['byte_range'] === null) {
                return false;
            }
            $byteRanges[] = $signature['byte_range'];
        }

        return $byteRanges;
    }

    /**
     * @param  list<list<int>>  $inputByteRanges
     */
    public static function isPrior(mixed $byteRange, array $inputByteRanges): bool
    {
        return is_array($byteRange) && in_array($byteRange, $inputByteRanges, true);
    }

    private static function isByteRange(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 4) {
            return false;
        }
        foreach ($value as $element) {
            if (! is_int($element) || $element < 0) {
                return false;
            }
        }

        return true;
    }
}
