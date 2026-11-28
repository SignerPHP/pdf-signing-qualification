<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class SignatureCoverageValidator extends ComparisonValidator
{
    private const string PDF_WHITESPACE = "\x00\t\n\x0C\r ";

    private const int REVISION_END_WINDOW = 128;

    public function analyzerId(): string
    {
        return 'qpdf-byte-range';
    }

    protected function property(): string
    {
        return 'byte_range';
    }

    protected function policy(): array
    {
        return [
            'id' => 'qpdf-byte-range-revisions',
            'description' => 'The signatures added by the implementation are the dictionaries with a /ByteRange that qpdf lists in the output and not in the input; the range of each must start at the first byte and leave out exactly the hexadecimal string of its own /Contents. One added signature must end at the last byte of the output. Of several, taken in the order of their range ends, each must end at the end of a revision, after startxref, its offset, %%EOF and an optional end of line, followed by the end of the file or by bytes that begin an object or a cross-reference table, and the next one must lie after that end; the output must end at the end of a revision, and revisions that add no signature are allowed between and after them',
            'parameters' => ['locator_arguments' => ['qpdf', ...SignatureListing::ARGUMENTS]],
        ];
    }

    protected function versionCommand(): array
    {
        return ['qpdf', '--version'];
    }

    protected function versionPattern(): string
    {
        return '/^qpdf version (\S+)$/m';
    }

    protected function compare(string $inputPath, string $outputPath): Judgement
    {
        $inputListing = SignatureListing::read($this->runner, $inputPath, $this->timeoutSeconds);
        if ($inputListing->result->timedOut) {
            return $this->timedOut($inputListing->result);
        }
        if ($inputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the input, so the added signature cannot be told apart: qpdf '.$inputListing->result->describe(), $inputListing->result->exitCode, $inputListing->result->output);
        }

        $outputListing = SignatureListing::read($this->runner, $outputPath, $this->timeoutSeconds);
        if ($outputListing->result->timedOut) {
            return $this->timedOut($outputListing->result);
        }
        if ($outputListing->signatures === null) {
            return new Judgement('inconclusive', 'qpdf could not list the signatures of the output: qpdf '.$outputListing->result->describe(), $outputListing->result->exitCode, $outputListing->result->output);
        }

        $inputKeys = $inputListing->keys();
        $added = array_values(array_filter($outputListing->signatures, static fn (array $signature): bool => ! isset($inputKeys[SignatureListing::key($signature)])));
        $exitCode = $outputListing->result->exitCode;
        if ($added === []) {
            return $outputListing->isComplete()
                ? new Judgement('invalid', 'The output holds no signature that is not already in the input', $exitCode, '')
                : new Judgement('inconclusive', 'No signature that is not already in the input was found in an output qpdf had to recover', $exitCode, '');
        }

        $outputSize = filesize($outputPath);
        if ($outputSize === false) {
            throw new RuntimeException("Unable to read the size of the document {$outputPath}");
        }

        $name = static fn (array $signature): string => $signature['field'] ?? $signature['object'];
        $signatures = count($added) === 1
            ? [$added[0] + self::check($added[0], $outputPath, $outputSize)]
            : self::checkRevisions($added, $outputPath, $outputSize, $name);
        if (! $inputListing->isComplete()) {
            $signatures = array_map(static fn (array $signature): array => $signature['outcome'] === 'valid' ? $signature : ['outcome' => 'inconclusive', 'problem' => $signature['problem'].', but qpdf had to recover the input, so this may be a signature of the input'] + $signature, $signatures);
        }
        $details = [
            'output_byte_size' => $outputSize,
            'signatures' => array_map(static fn (array $signature): array => array_diff_key($signature, ['contents' => true]), $signatures),
        ];

        $problems = array_filter($signatures, static fn (array $signature): bool => $signature['outcome'] !== 'valid');
        if ($problems === [] && count($signatures) === 1) {
            return new Judgement('valid', $name($signatures[0]).' covers every byte of the output except its /Contents', $exitCode, '', $details);
        }
        if ($problems === []) {
            return new Judgement('valid', implode(', ', array_map(static fn (array $signature): string => $name($signature)." covers every byte of the revision ending at byte {$signature['revision_end']} except its /Contents", $signatures)), $exitCode, '', $details);
        }

        $summary = implode('; ', array_map(static fn (array $signature): string => $name($signature).': '.$signature['problem'], $problems));
        $verdict = in_array('invalid', array_column($problems, 'outcome'), true) ? 'invalid' : 'inconclusive';

        return new Judgement($verdict, $summary, $exitCode, '', $details);
    }

    /**
     * @param  list<array{object: string, field: ?string, type: ?string, sub_filter: ?string, byte_range: ?list<int>, contents: ?string}>  $added
     * @param  callable(array): string  $name
     * @return list<array>
     */
    private static function checkRevisions(array $added, string $outputPath, int $size, callable $name): array
    {
        $rangeEnd = static fn (array $signature): array => $signature['byte_range'] === null ? [PHP_INT_MAX, PHP_INT_MAX] : [$signature['byte_range'][2] + $signature['byte_range'][3], $signature['byte_range'][1]];
        usort($added, static fn (array $first, array $second): int => $rangeEnd($first) <=> $rangeEnd($second));

        $signatures = [];
        foreach ($added as $index => $signature) {
            $next = $added[$index + 1] ?? null;
            $outcome = self::rangeShape($signature, $size) ?? self::revisionEnd($signature, $next, $outputPath, $size, $name);
            if (! isset($outcome['problem'])) {
                $outcome = (self::gap($signature, $outputPath) ?? ['outcome' => 'valid']) + $outcome;
            }
            $signatures[] = $signature + $outcome;
        }

        $last = array_key_last($signatures);
        if ($signatures[$last]['outcome'] === 'valid' && $signatures[$last]['revision_end'] < $size && self::revisionStartxref($outputPath, $size, $size) === null) {
            $signatures[$last] = ['outcome' => 'invalid', 'problem' => 'the output does not end at the end of a revision, so the bytes after the revision its range ends at are not complete revisions'] + $signatures[$last];
        }

        return $signatures;
    }

    /**
     * @param  callable(array): string  $name
     * @return array{outcome: string, problem?: string, revision_end?: int, revision_startxref?: int}
     */
    private static function revisionEnd(array $signature, ?array $next, string $outputPath, int $size, callable $name): array
    {
        [, , $secondOffset, $secondLength] = $signature['byte_range'];
        $end = $secondOffset + $secondLength;
        $startxref = self::revisionStartxref($outputPath, $end, $size);
        if ($startxref === null) {
            return ['outcome' => 'invalid', 'problem' => "the byte range ends at byte {$end}, which is not the end of a revision followed by an incremental update or by the end of the file"];
        }

        $revision = ['revision_end' => $end, 'revision_startxref' => $startxref];
        if ($next !== null && ($next['byte_range'] === null || $next['byte_range'][1] < $end)) {
            return ['outcome' => 'invalid', 'problem' => $name($next).', the next added signature, is not in the bytes after the revision its range ends at'] + $revision;
        }

        return ['outcome' => 'valid'] + $revision;
    }

    private static function revisionStartxref(string $outputPath, int $end, int $size): ?int
    {
        $whitespace = '['.preg_quote(self::PDF_WHITESPACE, '/').']';
        $tailStart = max(0, $end - self::REVISION_END_WINDOW);
        $tail = self::readBytes($outputPath, $tailStart, $end - $tailStart);
        if (preg_match('/startxref'.$whitespace.'+(\d+)'.$whitespace.'+%%EOF(?:\r\n|\r|\n)?\z/', $tail, $match) !== 1) {
            return null;
        }
        if ($end < $size) {
            $following = self::readBytes($outputPath, $end, min(self::REVISION_END_WINDOW, $size - $end));
            if (preg_match('/\A'.$whitespace.'*(?:\d+'.$whitespace.'+\d+'.$whitespace.'+obj(?![^'.preg_quote(self::PDF_WHITESPACE.'()<>[]{}/%', '/').'])|xref'.$whitespace.')/', $following) !== 1) {
                return null;
            }
        }

        return (int) $match[1];
    }

    /**
     * @param  array{object: string, field: ?string, type: ?string, sub_filter: ?string, byte_range: ?list<int>, contents: ?string}  $signature
     * @return array{outcome: string, problem?: string}
     */
    private static function check(array $signature, string $outputPath, int $size): array
    {
        $problem = self::rangeShape($signature, $size);
        if ($problem !== null) {
            return $problem;
        }

        [, , $secondOffset, $secondLength] = $signature['byte_range'];
        if ($secondOffset + $secondLength < $size) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range does not reach the end of the file, leaving '.($size - $secondOffset - $secondLength).' bytes uncovered'];
        }

        return self::gap($signature, $outputPath) ?? ['outcome' => 'valid'];
    }

    /**
     * @return array{outcome: string, problem: string}|null
     */
    private static function rangeShape(array $signature, int $size): ?array
    {
        $byteRange = $signature['byte_range'];
        if ($byteRange === null) {
            return ['outcome' => 'invalid', 'problem' => 'the /ByteRange is not an array of four integers'];
        }

        [$firstOffset, $firstLength, $secondOffset, $secondLength] = $byteRange;
        if ($firstOffset !== 0) {
            return ['outcome' => 'invalid', 'problem' => "the byte range starts at byte {$firstOffset}, leaving the bytes before it uncovered"];
        }
        if ($firstLength >= $secondOffset || $secondOffset + $secondLength > $size) {
            return ['outcome' => 'invalid', 'problem' => 'the byte range does not fit the file'];
        }

        return null;
    }

    /**
     * @return array{outcome: string, problem: string}|null
     */
    private static function gap(array $signature, string $outputPath): ?array
    {
        [, $firstLength, $secondOffset] = $signature['byte_range'];
        $whitespace = preg_quote(self::PDF_WHITESPACE, '/');
        $gap = self::readBytes($outputPath, $firstLength, $secondOffset - $firstLength);
        if (preg_match('/\A<([0-9A-Fa-f'.$whitespace.']*)>\z/', $gap, $match) !== 1) {
            return ['outcome' => 'invalid', 'problem' => 'the bytes the range leaves out are not the hexadecimal string of its /Contents'];
        }
        if ($signature['contents'] === null) {
            return ['outcome' => 'inconclusive', 'problem' => 'qpdf did not give the bytes of its /Contents, so the bytes the range leaves out cannot be explained'];
        }

        $hex = strtolower(str_replace(str_split(self::PDF_WHITESPACE), '', $match[1]));
        if (strlen($hex) % 2 !== 0) {
            $hex .= '0';
        }
        if ($hex !== $signature['contents']) {
            return ['outcome' => 'invalid', 'problem' => 'the bytes the range leaves out are not the hexadecimal string of its /Contents'];
        }

        return null;
    }

    private static function readBytes(string $path, int $offset, int $length): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false || fseek($handle, $offset) !== 0) {
            throw new RuntimeException("Unable to read the document {$path}");
        }

        try {
            $bytes = stream_get_contents($handle, $length);
            if ($bytes === false) {
                throw new RuntimeException("Unable to read the document {$path}");
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }
}
