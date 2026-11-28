<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class OutputRevisions
{
    public const array XREF_ARGUMENTS = ['--show-xref'];

    private const string WHITESPACE = "\x00\t\n\x0C\r ";

    private const string DELIMITERS = '()<>[]{}/%';

    private const int READ_CHUNK = 4096;

    private const array STRUCTURE_TYPES = ['/XRef', '/ObjStm'];

    private const array EXEMPT_CATALOG_KEYS = ['/DSS', '/Extensions'];

    /**
     * @param  list<array{end: int, marker_end: int, startxref: int, objects: ?QpdfObjects, path: ?string, followed_by_update: bool}>  $revisions
     * @param  array{outcome: string, problem: string, before: int}|null  $chainProblem
     */
    private function __construct(
        private readonly CommandRunner $runner,
        private readonly float $timeoutSeconds,
        public readonly array $revisions,
        public readonly ?array $chainProblem,
    ) {}

    public static function read(CommandRunner $runner, float $timeoutSeconds, string $outputPath, int $size, int $topEnd, QpdfObjects $topObjects, int $earliestContents, string $workDirectory): self
    {
        $revisions = [];
        $chainProblem = null;
        $end = $topEnd;
        $objects = $topEnd === $size ? $topObjects : null;
        while (true) {
            $marker = self::marker($outputPath, $end);
            if ($marker === null) {
                throw new RuntimeException("The revision end {$end} of {$outputPath} was not checked before it was read");
            }
            $revision = ['end' => $end, 'marker_end' => $marker['marker_end'], 'startxref' => $marker['startxref'], 'objects' => null, 'path' => null, 'followed_by_update' => self::beginsUpdate($outputPath, $end, $size)];
            if ($end <= $earliestContents) {
                $revisions[] = $revision;
                break;
            }

            $path = $end === $size ? $outputPath : self::prefix($outputPath, $end, $workDirectory);
            $objects ??= QpdfObjects::fromResult($runner->run(QpdfObjects::command($path), $timeoutSeconds, new CommandAccess([$path])));
            if ($objects === null || ! $objects->isComplete()) {
                $chainProblem = ['outcome' => 'inconclusive', 'problem' => "qpdf cannot read completely the document as it stands at the end of the revision ending at byte {$end}", 'before' => $end];
                break;
            }
            $revisions[] = ['objects' => $objects, 'path' => $path] + $revision;

            $previous = $objects->previousCrossReference();
            if ($previous === null) {
                break;
            }
            $previousEnd = $previous < $end ? self::endOfRevision($outputPath, $previous, $end) : null;
            if ($previousEnd === null || $previousEnd >= $end) {
                $chainProblem = ['outcome' => 'inconclusive', 'problem' => "the revision ending at byte {$end} names a previous cross-reference section at byte {$previous}, but no revision of the output ends with it", 'before' => $end];
                break;
            }
            $end = $previousEnd;
            $objects = null;
        }

        return new self($runner, $timeoutSeconds, array_reverse($revisions), $chainProblem);
    }

    /**
     * @return array{startxref: int, marker_end: int}|null
     */
    public static function marker(string $path, int $end): ?array
    {
        $tailStart = max(0, $end - 7);
        $tail = self::readBytes($path, $tailStart, $end - $tailStart);
        if (preg_match('/%%EOF(\r\n|\r|\n)?\z/', $tail, $match) !== 1) {
            return null;
        }
        $markerEnd = $end - strlen($match[1] ?? '');
        $position = self::skipBackward($path, $markerEnd - strlen('%%EOF'));
        if ($position === $markerEnd - strlen('%%EOF')) {
            return null;
        }
        $digitsEnd = $position;
        while ($position > 0 && ctype_digit(self::readBytes($path, $position - 1, 1))) {
            $position--;
        }
        if ($position === $digitsEnd || $position === self::skipBackward($path, $position)) {
            return null;
        }
        $startxref = self::readBytes($path, $position, $digitsEnd - $position);
        $position = self::skipBackward($path, $position);
        if ($position < strlen('startxref') || self::readBytes($path, $position - strlen('startxref'), strlen('startxref')) !== 'startxref') {
            return null;
        }

        return ['startxref' => (int) $startxref, 'marker_end' => $markerEnd];
    }

    public static function beginsUpdate(string $path, int $end, int $size): bool
    {
        $position = $end;
        while ($position < $size) {
            $chunk = self::readBytes($path, $position, min(self::READ_CHUNK, $size - $position));
            $skipped = strspn($chunk, self::WHITESPACE);
            if ($skipped < strlen($chunk)) {
                $position += $skipped;
                break;
            }
            $position += $skipped;
        }
        if ($position >= $size) {
            return $position === $end;
        }

        $whitespace = '['.preg_quote(self::WHITESPACE, '/').']';
        $following = self::readBytes($path, $position, min(self::READ_CHUNK, $size - $position));

        return preg_match('/\A(?:\d+'.$whitespace.'+\d+'.$whitespace.'+obj(?![^'.preg_quote(self::WHITESPACE.self::DELIMITERS, '/').'])|xref'.$whitespace.')/', $following) === 1;
    }

    /**
     * @return array{index: int, revision: array{end: int, marker_end: int, startxref: int, objects: ?QpdfObjects, path: ?string, followed_by_update: bool}}|null
     */
    public function endingAt(int $end): ?array
    {
        foreach ($this->revisions as $index => $revision) {
            if ($revision['end'] === $end || $revision['marker_end'] === $end) {
                return ['index' => $index, 'revision' => $revision];
            }
        }

        return null;
    }

    public function holding(int $offset): ?int
    {
        foreach ($this->revisions as $index => $revision) {
            $start = $index === 0 ? 0 : $this->revisions[$index - 1]['end'];
            if ($start <= $offset && $offset < $revision['end']) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array{outcome: string, problems: list<string>, objects: list<string>}
     */
    public function judgeUnsigned(int $beforeIndex, int $afterIndex): array
    {
        $before = $this->revisions[$beforeIndex];
        $after = $this->revisions[$afterIndex];
        if ($before['objects'] === null || $after['objects'] === null || $before['path'] === null || $after['path'] === null) {
            return ['outcome' => 'inconclusive', 'problems' => ['cannot be compared with the revision before them'], 'objects' => []];
        }
        $beforeEntries = $this->crossReference($before['path']);
        $afterEntries = $this->crossReference($after['path']);
        if ($beforeEntries === null || $afterEntries === null) {
            return ['outcome' => 'inconclusive', 'problems' => ['cannot be compared with the revision before them, because qpdf cannot list the cross-reference entries of both'], 'objects' => []];
        }

        $beforeObjects = $before['objects'];
        $afterObjects = $after['objects'];
        $beforeTrailer = $beforeObjects->trailer() ?? [];
        $afterTrailer = $afterObjects->trailer() ?? [];
        $root = $afterTrailer['/Root'] ?? null;
        if (! is_string($root) || $root !== ($beforeTrailer['/Root'] ?? null)) {
            return ['outcome' => 'invalid', 'problems' => ['change the /Root of the trailer'], 'objects' => []];
        }

        $structureOffsets = [];
        foreach (array_slice($this->revisions, $beforeIndex + 1, $afterIndex - $beforeIndex) as $revision) {
            $structureOffsets[$revision['startxref']] = true;
        }

        $changed = [];
        foreach ($afterEntries as $reference => $entry) {
            if (($beforeEntries[$reference] ?? null) !== $entry) {
                $changed[$reference] = $entry;
            }
        }
        $removed = array_keys(array_diff_key($beforeEntries, $afterEntries));

        $invalid = [];
        $inconclusive = [];
        $afterUse = self::uses($afterObjects, $afterTrailer);
        foreach ($changed as $reference => $entry) {
            if ($reference === $root) {
                $beforeCatalog = $beforeObjects->catalog();
                $afterCatalog = $afterObjects->catalog();
                if ($beforeCatalog === null || $afterCatalog === null || array_diff_key($beforeCatalog, array_flip(self::EXEMPT_CATALOG_KEYS)) != array_diff_key($afterCatalog, array_flip(self::EXEMPT_CATALOG_KEYS))) {
                    $invalid[] = "change the catalog {$reference} outside /DSS and /Extensions";
                }

                continue;
            }
            $kind = self::kind($reference, $afterObjects, $afterUse, $entry, $structureOffsets);
            if ($kind === 'document') {
                $invalid[] = "change {$reference}, which the document uses outside the DSS";
            } elseif ($kind === 'information') {
                $inconclusive[] = "change {$reference} of the document information dictionary, which ETSI EN 319 142-1 does not exempt";
            } elseif ($kind === 'unknown') {
                $inconclusive[] = "add or change {$reference}, which neither the DSS nor the document refers to";
            }
        }
        $beforeUse = self::uses($beforeObjects, $beforeTrailer);
        foreach ($removed as $reference) {
            $kind = self::kind($reference, $beforeObjects, $beforeUse, null, []);
            if ($kind === 'document') {
                $invalid[] = "remove {$reference}, which the document used outside the DSS";
            } elseif ($kind !== 'validation' && $kind !== 'structure') {
                $inconclusive[] = "remove {$reference}, which the DSS did not hold";
            }
        }

        $objects = array_values(array_unique([...array_keys($changed), ...$removed]));

        return match (true) {
            $invalid !== [] => ['outcome' => 'invalid', 'problems' => $invalid, 'objects' => $objects],
            $inconclusive !== [] => ['outcome' => 'inconclusive', 'problems' => $inconclusive, 'objects' => $objects],
            default => ['outcome' => 'valid', 'problems' => [], 'objects' => $objects],
        };
    }

    /**
     * @param  array{validation: array<string, true>, document: array<string, true>, information: array<string, true>}  $use
     * @param  array<int, true>  $structureOffsets
     */
    private static function kind(string $reference, QpdfObjects $objects, array $use, ?string $entry, array $structureOffsets): string
    {
        if (isset($use['document'][$reference])) {
            return 'document';
        }
        if (in_array($objects->streamType($reference), self::STRUCTURE_TYPES, true)) {
            return 'structure';
        }
        if (isset($use['validation'][$reference])) {
            return 'validation';
        }
        if (isset($use['information'][$reference])) {
            return 'information';
        }
        if ($entry !== null && preg_match('/^uncompressed; offset = (\d+)$/', $entry, $match) === 1 && isset($structureOffsets[(int) $match[1]])) {
            return 'structure';
        }

        return 'unknown';
    }

    /**
     * @return array{validation: array<string, true>, document: array<string, true>, information: array<string, true>}
     */
    private static function uses(QpdfObjects $objects, array $trailer): array
    {
        $catalog = $objects->catalog() ?? [];
        $root = $trailer['/Root'] ?? null;
        $exempt = array_intersect_key($catalog, array_flip(self::EXEMPT_CATALOG_KEYS));
        $document = $objects->reachable([...array_values(array_diff_key($catalog, $exempt)), $trailer['/Encrypt'] ?? null]);
        if (is_string($root)) {
            $document[$root] = true;
        }

        return [
            'validation' => $objects->reachable(array_values($exempt)),
            'document' => $document,
            'information' => $objects->reachable([$trailer['/Info'] ?? null]),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function crossReference(string $path): ?array
    {
        $result = $this->runner->run(['qpdf', ...self::XREF_ARGUMENTS, $path], $this->timeoutSeconds, new CommandAccess([$path]));
        if ($result->timedOut || $result->exitCode !== 0) {
            return null;
        }
        $entries = [];
        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            if (preg_match('/^(\d+)\/(\d+): (.+)$/', $line, $match) === 1) {
                $entries["{$match[1]} {$match[2]} R"] = $match[3];
            }
        }

        return $entries;
    }

    private static function endOfRevision(string $path, int $crossReference, int $limit): ?int
    {
        $whitespace = '['.preg_quote(self::WHITESPACE, '/').']';
        $section = self::readBytes($path, $crossReference, $limit - $crossReference);
        if (preg_match('/startxref'.$whitespace.'+0*'.$crossReference.$whitespace.'+%%EOF(?:\r\n|\r|\n)?/', $section, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $crossReference + $match[0][1] + strlen($match[0][0]);
    }

    private static function prefix(string $path, int $end, string $workDirectory): string
    {
        $prefixPath = "{$workDirectory}/revision-{$end}.pdf";
        $source = fopen($path, 'rb');
        $target = fopen($prefixPath, 'wb');
        if ($source === false || $target === false || stream_copy_to_stream($source, $target, $end) !== $end) {
            throw new RuntimeException("Unable to copy the first {$end} bytes of {$path}");
        }
        fclose($source);
        fclose($target);

        return $prefixPath;
    }

    private static function skipBackward(string $path, int $position): int
    {
        while ($position > 0) {
            $chunkStart = max(0, $position - self::READ_CHUNK);
            $chunk = self::readBytes($path, $chunkStart, $position - $chunkStart);
            $kept = strlen(rtrim($chunk, self::WHITESPACE));
            if ($kept > 0) {
                return $chunkStart + $kept;
            }
            $position = $chunkStart;
        }

        return $position;
    }

    private static function readBytes(string $path, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }
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
