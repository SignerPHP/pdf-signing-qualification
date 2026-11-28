<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class SignatureCoverageValidator extends ComparisonValidator
{
    private const string PDF_WHITESPACE = "\x00\t\n\x0C\r ";

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
            'description' => 'The signatures added by the implementation are the dictionaries with a /ByteRange that qpdf lists in the output and not in the input; the range of each must start at the first byte and leave out exactly the hexadecimal string of its own /Contents. One added signature, or several when qpdf had to recover the input, must end at the last byte of the output. Of several added to an input qpdf lists completely, taken in the order of their range ends, each must end at the end of the revision that holds its own /Contents, after startxref, its offset, %%EOF and an optional end of line, followed by the end of the file or by bytes that begin an object or a cross-reference table; the revisions are those of the /Prev chain from the end of the output, each read completely by qpdf as the document stands at its end. The output must end at the end of a revision. A revision that adds no signature is valid only when, against the revision just before it, it keeps /Root, /Info and /Encrypt in the trailer, is followed by the end of the file, an object or a cross-reference table, and changes no object other than the catalog outside /DSS and /Extensions, the objects only the DSS refers to and cross-reference or object streams, a rewritten object stream changing every object it holds (ETSI EN 319 142-1, clauses 5.4.2 and 5.6); a change to an object the document uses outside the DSS is invalid, and any other change, such as one to the document information dictionary, is inconclusive',
            'parameters' => ['locator_arguments' => ['qpdf', ...SignatureListing::ARGUMENTS], 'cross_reference_arguments' => ['qpdf', ...OutputRevisions::XREF_ARGUMENTS]],
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
        $byRevision = count($added) > 1 && $inputListing->isComplete();
        $unsignedRevisions = [];
        if ($byRevision) {
            $outputObjects = QpdfObjects::fromResult($outputListing->result);
            if ($outputObjects === null) {
                throw new RuntimeException("The listing of {$outputPath} could not be read twice the same way");
            }
            [$signatures, $unsignedRevisions] = $this->checkRevisions($added, $outputPath, $outputSize, $outputObjects, $name);
        } else {
            $signatures = array_map(static fn (array $signature): array => $signature + self::check($signature, $outputPath, $outputSize), $added);
        }
        if (! $inputListing->isComplete()) {
            $signatures = array_map(static fn (array $signature): array => $signature['outcome'] === 'valid' ? $signature : ['outcome' => 'inconclusive', 'problem' => $signature['problem'].', but qpdf had to recover the input, so this may be a signature of the input'] + $signature, $signatures);
        }
        $details = [
            'output_byte_size' => $outputSize,
            'signatures' => array_map(static fn (array $signature): array => array_diff_key($signature, ['contents' => true]), $signatures),
        ];
        if ($byRevision) {
            $details['unsigned_revisions'] = $unsignedRevisions;
        }

        $problems = array_filter($signatures, static fn (array $signature): bool => $signature['outcome'] !== 'valid');
        if ($problems === [] && ! $byRevision) {
            return new Judgement('valid', implode(', ', array_map($name, $signatures)).' covers every byte of the output except its /Contents', $exitCode, '', $details);
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
     * @return array{list<array>, list<array>}
     */
    private function checkRevisions(array $added, string $outputPath, int $size, QpdfObjects $outputObjects, callable $name): array
    {
        $rangeEnd = static fn (array $signature): array => $signature['byte_range'] === null ? [PHP_INT_MAX, PHP_INT_MAX] : [$signature['byte_range'][2] + $signature['byte_range'][3], $signature['byte_range'][1]];
        usort($added, static fn (array $first, array $second): int => $rangeEnd($first) <=> $rangeEnd($second));

        $problems = [];
        foreach ($added as $index => $signature) {
            $shape = self::rangeShape($signature, $size);
            $problems[$index] = $shape === null ? [] : [[$shape['outcome'], $shape['problem']]];
        }
        $ranged = array_filter($added, static fn (array $signature, int $index): bool => $problems[$index] === [], ARRAY_FILTER_USE_BOTH);
        if ($ranged === []) {
            return [self::withProblems($added, $problems, []), []];
        }

        $lastIndex = array_key_last($ranged);
        $lastEnd = $rangeEnd($ranged[$lastIndex])[0];
        $topEnd = $size;
        if (OutputRevisions::marker($outputPath, $size) === null) {
            $problems[array_key_last($added)][] = ['invalid', 'the output does not end at the end of a revision, so the bytes after the revision its range ends at are not complete revisions'];
            $topEnd = OutputRevisions::marker($outputPath, $lastEnd) === null ? null : $lastEnd;
        }
        if ($topEnd === null) {
            return [self::withProblems($added, $problems, []), []];
        }

        $earliestContents = min(array_map(static fn (array $signature): int => $signature['byte_range'][1], $ranged));
        $workDirectory = sys_get_temp_dir().'/qpdf-byte-range-'.bin2hex(random_bytes(8));
        if (! mkdir($workDirectory, 0700)) {
            throw new RuntimeException("Unable to create the work directory {$workDirectory}");
        }

        try {
            $revisions = OutputRevisions::read($this->runner, $this->timeoutSeconds, $outputPath, $size, $topEnd, $outputObjects, $earliestContents, $workDirectory);
            $revisionDetails = [];
            $signedRevisions = [];
            foreach ($ranged as $index => $signature) {
                $own = $revisions->holding($signature['byte_range'][1]);
                if ($own !== null && ! isset($signedRevisions[$own])) {
                    $signedRevisions[$own] = $index;
                }
                [$end] = $rangeEnd($signature);
                $found = $revisions->endingAt($end);
                if ($found === null || $found['revision']['objects'] === null) {
                    $chainProblem = $revisions->chainProblem;
                    $problems[$index][] = $chainProblem !== null && $end <= $chainProblem['before']
                        ? [$chainProblem['outcome'], $chainProblem['problem']]
                        : ['invalid', "the byte range ends at byte {$end}, which is not the end of a revision followed by an incremental update or by the end of the file"];

                    continue;
                }
                $revision = $found['revision'];
                $revisionDetails[$index] = ['revision_end' => $revision['end'], 'revision_startxref' => $revision['startxref']];
                if (! $revision['followed_by_update']) {
                    $problems[$index][] = ['invalid', "the byte range ends at byte {$end}, which is not the end of a revision followed by an incremental update or by the end of the file"];

                    continue;
                }
                if ($own !== $found['index']) {
                    $problems[$index][] = ['invalid', "the byte range ends at the end of the revision ending at byte {$revision['end']}, but its own dictionary is in an earlier revision"];

                    continue;
                }
                $next = $added[$index + 1] ?? null;
                if ($next !== null && ($next['byte_range'] === null || $next['byte_range'][1] < $end)) {
                    $problems[$index][] = ['invalid', $name($next).', the next added signature, is not in the bytes after the revision its range ends at'];
                }
            }

            $unsignedRevisions = [];
            if ($signedRevisions !== []) {
                ksort($signedRevisions);
                $revisionIndexes = array_keys($signedRevisions);
                $lastRevisionIndex = count($revisions->revisions) - 1;
                foreach ($revisionIndexes as $position => $revisionIndex) {
                    $nextSigned = $revisionIndexes[$position + 1] ?? $lastRevisionIndex + 1;
                    if ($nextSigned - $revisionIndex <= 1) {
                        continue;
                    }
                    $judgement = $revisions->judgeUnsigned($revisionIndex, $nextSigned - 1);
                    $unsignedRevisions[] = [
                        'after_revision_end' => $revisions->revisions[$revisionIndex]['end'],
                        'revision_ends' => array_map(static fn (array $revision): int => $revision['end'], array_slice($revisions->revisions, $revisionIndex + 1, $nextSigned - $revisionIndex - 1)),
                        'outcome' => $judgement['outcome'],
                        'changed_objects' => $judgement['objects'],
                        'problems' => $judgement['problems'],
                    ];
                    if ($judgement['outcome'] !== 'valid') {
                        $lastUnsignedEnd = $revisions->revisions[$nextSigned - 1]['end'];
                        $problems[$signedRevisions[$revisionIndex]][] = [$judgement['outcome'], "the revisions after its own up to byte {$lastUnsignedEnd}, which add no signature, ".implode(', ', $judgement['problems'])];
                    }
                }
            }
            foreach ($ranged as $index => $signature) {
                $gap = self::gap($signature, $outputPath);
                if ($gap !== null) {
                    $problems[$index][] = [$gap['outcome'], $gap['problem']];
                }
            }

            return [self::withProblems($added, $problems, $revisionDetails), $unsignedRevisions];
        } finally {
            exec('rm -rf '.escapeshellarg($workDirectory));
        }
    }

    /**
     * @param  list<array>  $added
     * @param  array<array-key, list<array{string, string}>>  $problems
     * @param  array<int, array{revision_end: int, revision_startxref: int}>  $revisionDetails
     * @return list<array>
     */
    private static function withProblems(array $added, array $problems, array $revisionDetails): array
    {
        $signatures = [];
        foreach ($added as $index => $signature) {
            $found = $problems[$index] ?? [];
            $outcomes = array_column($found, 0);
            $outcome = match (true) {
                in_array('invalid', $outcomes, true) => 'invalid',
                $outcomes !== [] => 'inconclusive',
                default => 'valid',
            };
            $result = ['outcome' => $outcome];
            if ($found !== []) {
                $result['problem'] = implode('; and ', array_column(array_filter($found, static fn (array $problem): bool => $problem[0] === $outcome), 1));
            }
            $signatures[] = $signature + $result + ($revisionDetails[$index] ?? []);
        }

        return $signatures;
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
