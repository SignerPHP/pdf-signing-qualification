<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class SignatureListing
{
    public const array ARGUMENTS = ['--json=2', '--json-key=qpdf', '--no-warn'];

    private const string REFERENCE_PATTERN = '/^\d+ \d+ R$/';

    /**
     * @param  list<array{object: string, field: ?string, byte_range: ?list<int>, contents: ?string}>|null  $signatures
     */
    private function __construct(
        public readonly CommandResult $result,
        public readonly ?array $signatures,
    ) {}

    public static function read(CommandRunner $runner, string $documentPath, float $timeoutSeconds): self
    {
        $result = $runner->run(['qpdf', ...self::ARGUMENTS, $documentPath], $timeoutSeconds);
        if ($result->timedOut || ! in_array($result->exitCode, [0, 3], true)) {
            return new self($result, null);
        }

        $decoded = json_decode($result->output, true);
        $objects = is_array($decoded) ? ($decoded['qpdf'][1] ?? null) : null;

        return new self($result, is_array($objects) ? self::signatures($objects) : null);
    }

    public function isComplete(): bool
    {
        return $this->result->exitCode === 0;
    }

    /**
     * @return array<string, true>
     */
    public function keys(): array
    {
        return array_fill_keys(array_map(self::key(...), $this->signatures ?? []), true);
    }

    /**
     * @param  array{object: string, field: ?string, byte_range: ?list<int>, contents: ?string}  $signature
     */
    public static function key(array $signature): string
    {
        return json_encode([$signature['byte_range'], $signature['contents']], JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array{object: string, field: ?string, byte_range: ?list<int>, contents: ?string}>
     */
    private static function signatures(array $objects): array
    {
        $fieldNames = self::fieldNames($objects);
        $signatures = [];
        foreach ($objects as $key => $object) {
            $reference = is_string($key) && str_starts_with($key, 'obj:') ? substr($key, strlen('obj:')) : $key;
            if (! is_array($object) || ($reference !== 'trailer' && $reference === $key)) {
                continue;
            }
            $dictionaries = [];
            self::collectSignatureDictionaries($object['value'] ?? $object['stream']['dict'] ?? null, $dictionaries);
            foreach ($dictionaries as $dictionary) {
                $signatures[] = [
                    'object' => $reference,
                    'field' => $fieldNames[$reference] ?? null,
                    'byte_range' => self::byteRange($dictionary['/ByteRange'], $objects),
                    'contents' => self::binaryString(self::resolve($dictionary['/Contents'] ?? null, $objects)),
                ];
            }
        }

        return $signatures;
    }

    /**
     * @param  list<array>  $dictionaries
     */
    private static function collectSignatureDictionaries(mixed $value, array &$dictionaries): void
    {
        if (! is_array($value)) {
            return;
        }
        if (array_key_exists('/ByteRange', $value)) {
            $dictionaries[] = $value;
        }
        foreach ($value as $child) {
            self::collectSignatureDictionaries($child, $dictionaries);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function fieldNames(array $objects): array
    {
        $root = self::resolve($objects['trailer']['value']['/Root'] ?? null, $objects);
        $acroForm = is_array($root) ? self::resolve($root['/AcroForm'] ?? null, $objects) : null;
        $fields = is_array($acroForm) ? self::resolve($acroForm['/Fields'] ?? null, $objects) : null;

        $names = [];
        $visited = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            self::collectFieldNames($field, null, $objects, $names, $visited);
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, true>  $visited
     */
    private static function collectFieldNames(mixed $field, ?string $parentName, array $objects, array &$names, array &$visited): void
    {
        $holder = null;
        if (is_string($field) && preg_match(self::REFERENCE_PATTERN, $field) === 1) {
            if (isset($visited[$field])) {
                return;
            }
            $visited[$field] = true;
            $holder = $field;
        }
        $dictionary = self::resolve($field, $objects);
        if (! is_array($dictionary)) {
            return;
        }

        $partialName = self::textString(self::resolve($dictionary['/T'] ?? null, $objects));
        $name = $partialName === null ? $parentName : ($parentName === null ? $partialName : "{$parentName}.{$partialName}");

        $value = $dictionary['/V'] ?? null;
        if (is_string($value) && preg_match(self::REFERENCE_PATTERN, $value) === 1) {
            $holder = $value;
            $value = self::resolve($value, $objects);
        }
        if ($holder !== null && is_array($value) && array_key_exists('/ByteRange', $value) && $name !== null) {
            $names[$holder] = $name;
        }

        $kids = self::resolve($dictionary['/Kids'] ?? null, $objects);
        foreach (is_array($kids) ? $kids : [] as $kid) {
            self::collectFieldNames($kid, $name, $objects, $names, $visited);
        }
    }

    /**
     * @return list<int>|null
     */
    private static function byteRange(mixed $value, array $objects): ?array
    {
        $value = self::resolve($value, $objects);
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 4) {
            return null;
        }

        $byteRange = [];
        foreach ($value as $element) {
            $element = self::resolve($element, $objects);
            if (! is_int($element) || $element < 0) {
                return null;
            }
            $byteRange[] = $element;
        }

        return $byteRange;
    }

    private static function binaryString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (true) {
            str_starts_with($value, 'b:') => strtolower(substr($value, 2)),
            str_starts_with($value, 'u:') && mb_check_encoding(substr($value, 2), 'ASCII') => bin2hex(substr($value, 2)),
            default => null,
        };
    }

    private static function textString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (true) {
            str_starts_with($value, 'u:') => substr($value, 2),
            str_starts_with($value, 'b:') && mb_check_encoding((string) hex2bin(substr($value, 2)), 'UTF-8') => (string) hex2bin(substr($value, 2)),
            str_starts_with($value, 'b:') => $value,
            default => null,
        };
    }

    private static function resolve(mixed $value, array $objects): mixed
    {
        if (is_string($value) && preg_match(self::REFERENCE_PATTERN, $value) === 1) {
            return $objects['obj:'.$value]['value'] ?? null;
        }

        return $value;
    }
}
