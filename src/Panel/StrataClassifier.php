<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Panel;

use SignerPHP\PdfSigningQualification\Validation\CommandResult;
use SignerPHP\PdfSigningQualification\Validation\CommandRunner;

final class StrataClassifier
{
    private const float TIMEOUT_SECONDS = 120.0;

    private const array QPDF_EXIT_CODES = [0, 2, 3];

    private const string MALFORMED_WARNING = "/file is damaged|can't find PDF header|xref|cross-reference stream data|object stream/i";

    private const string SIGNATURE_TIME_STAMP_TOKEN = '060b2a864886f70d010910020e';

    private const string NON_EMPTY_ADOBE_REVOCATION_INFO_ARCHIVAL = '/06092a864886f72f01010831(?:[0-7][0-9a-f]|81[0-9a-f]{2}|82[0-9a-f]{4})30(?!00)/';

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly int $largeByteSize,
        private readonly int $largePageCount,
    ) {}

    /**
     * @return list<string>
     */
    public function classify(string $path): array
    {
        $bytes = (string) file_get_contents($path);
        $document = $this->documentStructure($path);
        $objects = $document['objects'] ?? null;

        $strata = self::crossReferenceStrata($bytes);
        if (preg_match('#/Type\s*/ObjStm(?![A-Za-z0-9])#', $bytes) === 1) {
            $strata[] = 'objstm';
        }
        if (preg_match('#/Filter\s*/Standard(?![A-Za-z0-9])#', $bytes) === 1) {
            $strata[] = 'encrypted';
        }
        if (self::revisionCount($bytes) > 1) {
            $strata[] = 'incremental';
        }

        $signatureContents = $objects === null ? self::signatureContentsInBytes($bytes) : self::signatureContents($objects);
        $hasByteRange = $objects === null ? str_contains($bytes, '/ByteRange') : self::anyObjectHasKey($objects, '/ByteRange');
        if ($hasByteRange) {
            $strata[] = 'signed';
        }
        if ($this->isMalformed($path)) {
            $strata[] = 'malformed';
        }
        if (self::hasFormFields($document['acroform'] ?? null)) {
            $strata[] = 'forms';
        }
        $version = self::headerVersion($bytes);
        if ($version !== null && version_compare($version, '1.3', '<')) {
            $strata[] = 'legacy';
        }
        $pageCount = isset($document['pages']) && is_array($document['pages']) ? count($document['pages']) : null;
        if (strlen($bytes) >= $this->largeByteSize || ($pageCount !== null && $pageCount >= $this->largePageCount)) {
            $strata[] = 'large';
        }
        $isDocumentTimeStamp = $objects === null
            ? preg_match('#/SubFilter\s*/ETSI\.RFC3161(?![A-Za-z0-9])#', $bytes) === 1
            : self::anyObjectHasValue($objects, '/SubFilter', '/ETSI.RFC3161');
        if ($isDocumentTimeStamp || self::anyContains($signatureContents, self::SIGNATURE_TIME_STAMP_TOKEN)) {
            $strata[] = 'timestamped';
        }
        $hasSecurityStore = $objects === null
            ? preg_match('#/DSS\s*(\d+\s+\d+\s+R|<<)#', $bytes) === 1
            : self::anyObjectHasKey($objects, '/DSS');
        if ($hasSecurityStore || self::anyMatches($signatureContents, self::NON_EMPTY_ADOBE_REVOCATION_INFO_ARCHIVAL)) {
            $strata[] = 'revocation';
        }

        return $strata;
    }

    public static function headerVersion(string $bytes): ?string
    {
        return preg_match('/%PDF-(\d\.\d)/', substr($bytes, 0, 1024), $matches) === 1 ? $matches[1] : null;
    }

    private static function revisionCount(string $bytes): int
    {
        $headerOffset = strpos($bytes, '%PDF-');
        $isLinearized = preg_match('#/Linearized\s#', substr($bytes, $headerOffset === false ? 0 : $headerOffset, 1024)) === 1;

        return substr_count($bytes, 'startxref') - ($isLinearized ? 1 : 0);
    }

    /**
     * @return list<string>
     */
    private static function crossReferenceStrata(string $bytes): array
    {
        if (! self::startxrefPointsToCrossReference($bytes)) {
            return ['none'];
        }
        if (preg_match('#/XRefStm\s+\d#', $bytes) === 1) {
            return ['hybrid'];
        }

        $hasTable = preg_match('/(?<![A-Za-z])xref[ \t]*[\r\n]/', $bytes) === 1;
        $hasStream = preg_match('#/Type\s*/XRef(?![A-Za-z0-9])#', $bytes) === 1;

        return match (true) {
            $hasTable && ! $hasStream => ['classic'],
            $hasStream && ! $hasTable => ['stream'],
            default => [],
        };
    }

    private static function startxrefPointsToCrossReference(string $bytes): bool
    {
        if (preg_match_all('/startxref\s+(\d+)/', $bytes, $matches) < 1) {
            return false;
        }
        $offset = (int) end($matches[1]);
        $headerOffset = strpos($bytes, '%PDF-');

        foreach (array_unique([$offset, $offset + ($headerOffset === false ? 0 : $headerOffset)]) as $candidate) {
            $section = ltrim(substr($bytes, $candidate, 64));
            if (str_starts_with($section, 'xref') || preg_match('/^\d+\s+\d+\s+obj/', $section) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isMalformed(string $path): bool
    {
        $result = $this->qpdf(['qpdf', '--check', $path], $path);
        foreach (explode("\n", $result->output) as $line) {
            if ((str_starts_with($line, 'WARNING:') || str_starts_with($line, 'qpdf:')) && preg_match(self::MALFORMED_WARNING, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{objects?: array<string, mixed>, acroform?: mixed, pages?: mixed}
     */
    private function documentStructure(string $path): array
    {
        $result = $this->qpdf(
            ['sh', '-c', 'exec qpdf "$@" 2>/dev/null', 'qpdf', '--json=2', '--json-key=qpdf', '--json-key=pages', '--json-key=acroform', $path],
            $path,
        );
        if ($result->exitCode === 2) {
            return [];
        }
        $document = json_decode($result->output, true);
        if (! is_array($document)) {
            return [];
        }

        $structure = ['acroform' => $document['acroform'] ?? null, 'pages' => $document['pages'] ?? null];
        if (isset($document['qpdf'][1]) && is_array($document['qpdf'][1])) {
            $structure['objects'] = $document['qpdf'][1];
        }

        return $structure;
    }

    /**
     * @param  list<string>  $command
     */
    private function qpdf(array $command, string $path): CommandResult
    {
        $result = $this->runner->run($command, self::TIMEOUT_SECONDS);
        if ($result->timedOut || ! in_array($result->exitCode, self::QPDF_EXIT_CODES, true)) {
            throw new UnclassifiableFile("qpdf {$result->describe()}");
        }

        return $result;
    }

    private static function hasFormFields(mixed $acroform): bool
    {
        if (! is_array($acroform) || ! isset($acroform['fields']) || ! is_array($acroform['fields'])) {
            return false;
        }
        foreach ($acroform['fields'] as $field) {
            if (is_array($field) && ($field['fieldtype'] ?? null) !== '/Sig') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $objects
     * @return list<string>
     */
    private static function signatureContents(array $objects): array
    {
        $contents = [];
        foreach ($objects as $object) {
            $dictionary = self::dictionary($object);
            if (isset($dictionary['/ByteRange'], $dictionary['/Contents']) && is_string($dictionary['/Contents'])) {
                $contents[] = strtolower(preg_replace('/^b:/', '', $dictionary['/Contents']) ?? '');
            }
        }

        return $contents;
    }

    /**
     * @return list<string>
     */
    private static function signatureContentsInBytes(string $bytes): array
    {
        preg_match_all('#/Contents\s*<([0-9A-Fa-f\s]+)>#', $bytes, $matches);

        return array_map(static fn (string $hex): string => strtolower(preg_replace('/\s+/', '', $hex) ?? ''), $matches[1]);
    }

    /**
     * @param  list<string>  $haystacks
     */
    private static function anyContains(array $haystacks, string $needle): bool
    {
        foreach ($haystacks as $haystack) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $haystacks
     */
    private static function anyMatches(array $haystacks, string $pattern): bool
    {
        foreach ($haystacks as $haystack) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $objects
     */
    private static function anyObjectHasKey(array $objects, string $key): bool
    {
        foreach ($objects as $object) {
            if (array_key_exists($key, self::dictionary($object))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $objects
     */
    private static function anyObjectHasValue(array $objects, string $key, string $value): bool
    {
        foreach ($objects as $object) {
            if ((self::dictionary($object)[$key] ?? null) === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function dictionary(mixed $object): array
    {
        if (! is_array($object)) {
            return [];
        }
        $value = $object['value'] ?? ($object['stream']['dict'] ?? null);

        return is_array($value) ? $value : [];
    }
}
