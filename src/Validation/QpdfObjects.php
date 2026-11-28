<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class QpdfObjects
{
    public const array ARGUMENTS = ['--json=2', '--json-key=qpdf', '--no-warn'];

    private const string REFERENCE_PATTERN = '/^\d+ \d+ R$/';

    /**
     * @param  array<string, mixed>  $objects
     */
    private function __construct(
        public readonly CommandResult $result,
        private readonly array $objects,
    ) {}

    /**
     * @return list<string>
     */
    public static function command(string $documentPath): array
    {
        return ['qpdf', ...self::ARGUMENTS, $documentPath];
    }

    public static function fromResult(CommandResult $result): ?self
    {
        if ($result->timedOut || ! in_array($result->exitCode, [0, 3], true)) {
            return null;
        }
        $decoded = json_decode($result->output, true);
        $objects = is_array($decoded) && is_array($decoded['qpdf'][1] ?? null) ? $decoded['qpdf'][1] : null;

        return $objects === null ? null : new self($result, $objects);
    }

    public function isComplete(): bool
    {
        return $this->result->exitCode === 0;
    }

    public function resolve(mixed $value): mixed
    {
        if (is_string($value) && preg_match(self::REFERENCE_PATTERN, $value) === 1) {
            $object = $this->objects['obj:'.$value] ?? null;

            return is_array($object) ? ($object['value'] ?? $object['stream']['dict'] ?? null) : null;
        }

        return $value;
    }

    public function resolveNested(mixed $value): mixed
    {
        $expanded = [];

        return $this->expand($value, $expanded);
    }

    /**
     * @param  array<string, true>  $expanded
     */
    private function expand(mixed $value, array &$expanded): mixed
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $items[$key] = $this->expand($item, $expanded);
            }

            return $items;
        }
        if (! is_string($value) || preg_match(self::REFERENCE_PATTERN, $value) !== 1 || isset($expanded[$value])) {
            return $value;
        }
        $object = $this->resolve($value);
        if ($object === null) {
            return $value;
        }
        $expanded[$value] = true;

        return ['object' => $value, 'value' => $this->expand($object, $expanded)];
    }

    public function trailer(): ?array
    {
        $trailer = $this->objects['trailer']['value'] ?? null;

        return is_array($trailer) ? $trailer : null;
    }

    public function previousCrossReference(): ?int
    {
        $previous = $this->resolve($this->trailer()['/Prev'] ?? null);

        return is_int($previous) && $previous >= 0 ? $previous : null;
    }

    public function streamType(string $reference): ?string
    {
        $object = $this->objects['obj:'.$reference] ?? null;
        $type = is_array($object) ? ($object['stream']['dict']['/Type'] ?? null) : null;

        return is_string($type) ? $type : null;
    }

    /**
     * @param  list<mixed>  $values
     * @return array<string, true>
     */
    public function reachable(array $values): array
    {
        $reached = [];
        $pending = $values;
        while ($pending !== []) {
            $value = array_pop($pending);
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pending[] = $item;
                }

                continue;
            }
            if (! is_string($value) || preg_match(self::REFERENCE_PATTERN, $value) !== 1 || isset($reached[$value])) {
                continue;
            }
            $reached[$value] = true;
            $pending[] = $this->resolve($value);
        }

        return $reached;
    }

    public function catalog(): ?array
    {
        $trailer = $this->objects['trailer']['value'] ?? null;
        $catalog = is_array($trailer) ? $this->resolve($trailer['/Root'] ?? null) : null;

        return is_array($catalog) ? $catalog : null;
    }

    /**
     * @return array<string, array>
     */
    public function signatureDictionaries(): array
    {
        $dictionaries = [];
        foreach ($this->objects as $key => $object) {
            if (! is_string($key) || ! str_starts_with($key, 'obj:') || ! is_array($object)) {
                continue;
            }
            $value = $object['value'] ?? null;
            if (is_array($value) && array_key_exists('/ByteRange', $value)) {
                $dictionaries[substr($key, strlen('obj:'))] = $value;
            }
        }

        return $dictionaries;
    }

    /**
     * @return array<string, true>
     */
    public function signatureFieldValues(): array
    {
        $catalog = $this->catalog();
        $acroForm = $catalog === null ? null : $this->resolve($catalog['/AcroForm'] ?? null);
        $fields = is_array($acroForm) ? $this->resolve($acroForm['/Fields'] ?? null) : null;

        $values = [];
        $visited = [];
        $pending = is_array($fields) ? array_values($fields) : [];
        while ($pending !== []) {
            $field = array_shift($pending);
            if (is_string($field)) {
                if (isset($visited[$field])) {
                    continue;
                }
                $visited[$field] = true;
            }
            $dictionary = $this->resolve($field);
            if (! is_array($dictionary)) {
                continue;
            }
            if (is_string($dictionary['/V'] ?? null) && preg_match(self::REFERENCE_PATTERN, $dictionary['/V']) === 1) {
                $values[$dictionary['/V']] = true;
            }
            $kids = $this->resolve($dictionary['/Kids'] ?? null);
            foreach (is_array($kids) ? $kids : [] as $kid) {
                $pending[] = $kid;
            }
        }

        return $values;
    }

    /**
     * @return list<int>|null
     */
    public function byteRange(array $dictionary): ?array
    {
        $value = $this->resolve($dictionary['/ByteRange'] ?? null);
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 4) {
            return null;
        }
        $range = [];
        foreach ($value as $element) {
            $element = $this->resolve($element);
            if (! is_int($element) || $element < 0) {
                return null;
            }
            $range[] = $element;
        }

        return $range;
    }

    public function bytes(mixed $value): ?string
    {
        $value = $this->resolve($value);
        if (! is_string($value)) {
            return null;
        }

        return match (true) {
            str_starts_with($value, 'b:') => hex2bin(substr($value, 2)) ?: null,
            str_starts_with($value, 'u:') => substr($value, 2),
            default => null,
        };
    }
}
