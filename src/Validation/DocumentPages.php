<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class DocumentPages
{
    private const string REFERENCE_PATTERN = '/^\d+ \d+ R$/';

    private const int MAXIMUM_DEPTH = 64;

    /**
     * @param  array<string, mixed>  $objects
     * @param  list<string>  $pages
     */
    private function __construct(
        private readonly array $objects,
        private readonly array $pages,
    ) {}

    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);
        $objects = is_array($decoded) ? ($decoded['qpdf'][1] ?? null) : null;
        $pages = is_array($decoded) ? ($decoded['pages'] ?? null) : null;
        if (! is_array($objects) || ! is_array($pages)) {
            return null;
        }

        $pageObjects = [];
        foreach ($pages as $page) {
            $reference = is_array($page) ? ($page['object'] ?? null) : null;
            if (! is_string($reference) || preg_match(self::REFERENCE_PATTERN, $reference) !== 1) {
                return null;
            }
            $pageObjects[] = $reference;
        }

        return new self($objects, $pageObjects);
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /**
     * @return list<int|float>|null
     */
    public function mediaBox(int $page): ?array
    {
        return self::rectangle($this->resolve($this->inherited($page, '/MediaBox')));
    }

    public function rotation(int $page): int
    {
        $rotation = $this->resolve($this->inherited($page, '/Rotate'));

        return is_int($rotation) && $rotation % 90 === 0 ? (($rotation % 360) + 360) % 360 : 0;
    }

    /**
     * @return list<array{object: ?string, page: int, rectangle: ?list<int|float>, flags: int, appearance: ?string}>
     */
    public function widgetsOf(string $signatureObject): array
    {
        $widgets = [];
        $seen = [];
        foreach ($this->pages as $index => $pageReference) {
            $page = $this->resolve($pageReference);
            $annotations = is_array($page) ? $this->resolve($page['/Annots'] ?? null) : null;
            foreach (is_array($annotations) ? $annotations : [] as $annotation) {
                $reference = is_string($annotation) && preg_match(self::REFERENCE_PATTERN, $annotation) === 1 ? $annotation : null;
                $dictionary = $this->resolve($annotation);
                if (! is_array($dictionary) || ! $this->isWidgetOf($reference, $dictionary, $signatureObject)) {
                    continue;
                }
                if ($reference !== null) {
                    if (isset($seen[$reference])) {
                        continue;
                    }
                    $seen[$reference] = true;
                }
                $flags = $this->resolve($dictionary['/F'] ?? 0);
                $widgets[] = [
                    'object' => $reference,
                    'page' => $index + 1,
                    'rectangle' => self::rectangle($this->resolve($dictionary['/Rect'] ?? null)),
                    'flags' => is_int($flags) ? $flags : 0,
                    'appearance' => $this->normalAppearance($dictionary),
                ];
            }
        }

        return $widgets;
    }

    public function hasWidgetOutsidePages(string $signatureObject): bool
    {
        foreach ($this->objects as $key => $object) {
            $reference = is_string($key) && str_starts_with($key, 'obj:') ? substr($key, strlen('obj:')) : null;
            $dictionary = is_array($object) ? ($object['value'] ?? null) : null;
            if ($reference !== null && is_array($dictionary) && $this->isWidgetOf($reference, $dictionary, $signatureObject)) {
                return true;
            }
        }

        return false;
    }

    private function isWidgetOf(?string $reference, array $dictionary, string $signatureObject): bool
    {
        if ($this->resolve($dictionary['/Subtype'] ?? null) !== '/Widget') {
            return false;
        }

        $node = $dictionary;
        for ($depth = 0; $depth < self::MAXIMUM_DEPTH && is_array($node); $depth++) {
            if ($reference === $signatureObject || ($node['/V'] ?? null) === $signatureObject) {
                return true;
            }
            $parent = $node['/Parent'] ?? null;
            if (! is_string($parent) || preg_match(self::REFERENCE_PATTERN, $parent) !== 1) {
                return false;
            }
            $reference = $parent;
            $node = $this->resolve($parent);
        }

        return false;
    }

    private function normalAppearance(array $dictionary): ?string
    {
        $appearances = $this->resolve($dictionary['/AP'] ?? null);
        $normal = is_array($appearances) ? ($appearances['/N'] ?? null) : null;
        if (! is_string($normal) || preg_match(self::REFERENCE_PATTERN, $normal) !== 1) {
            return null;
        }
        $object = $this->objects['obj:'.$normal] ?? null;

        return is_array($object) && is_array($object['stream'] ?? null) ? $normal : null;
    }

    private function inherited(int $page, string $key): mixed
    {
        $node = $this->resolve($this->pages[$page - 1] ?? null);
        for ($depth = 0; $depth < self::MAXIMUM_DEPTH && is_array($node); $depth++) {
            if (array_key_exists($key, $node)) {
                return $node[$key];
            }
            $node = $this->resolve($node['/Parent'] ?? null);
        }

        return null;
    }

    /**
     * @return list<int|float>|null
     */
    private static function rectangle(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 4) {
            return null;
        }
        foreach ($value as $coordinate) {
            if (! is_int($coordinate) && ! is_float($coordinate)) {
                return null;
            }
        }

        return [min($value[0], $value[2]), min($value[1], $value[3]), max($value[0], $value[2]), max($value[1], $value[3])];
    }

    private function resolve(mixed $value): mixed
    {
        if (is_string($value) && preg_match(self::REFERENCE_PATTERN, $value) === 1) {
            $object = $this->objects['obj:'.$value] ?? null;

            return is_array($object) ? ($object['value'] ?? $object['stream']['dict'] ?? null) : null;
        }

        return $value;
    }
}
