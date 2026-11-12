<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

final class Raster
{
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        private readonly string $pixels,
    ) {}

    public static function fromPortableGraymap(string $path): ?self
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false || preg_match('/\AP5\s+(\d+)\s+(\d+)\s+255\s/', $contents, $header) !== 1) {
            return null;
        }

        $width = (int) $header[1];
        $height = (int) $header[2];
        $pixels = substr($contents, strlen($header[0]));
        if ($width === 0 || $height === 0 || strlen($pixels) !== $width * $height) {
            return null;
        }

        return new self($width, $height, $pixels);
    }

    /**
     * @param  list<int>|null  $field
     * @return array{int, int, list<int>}
     */
    public function differences(self $other, ?array $field, int $margin): array
    {
        [$fieldLeft, $fieldTop, $fieldRight, $fieldBottom] = $field === null
            ? [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN]
            : [$field[0] - $margin, $field[1] - $margin, $field[2] + $margin, $field[3] + $margin];

        $inside = 0;
        $outside = 0;
        $bounds = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN];
        for ($row = 0; $row < $this->height; $row++) {
            $offset = $row * $this->width;
            $ownRow = substr($this->pixels, $offset, $this->width);
            $otherRow = substr($other->pixels, $offset, $this->width);
            if ($ownRow === $otherRow) {
                continue;
            }
            for ($column = 0; $column < $this->width; $column++) {
                if ($ownRow[$column] === $otherRow[$column]) {
                    continue;
                }
                if ($column >= $fieldLeft && $column < $fieldRight && $row >= $fieldTop && $row < $fieldBottom) {
                    $inside++;

                    continue;
                }
                $outside++;
                $bounds = [min($bounds[0], $column), min($bounds[1], $row), max($bounds[2], $column), max($bounds[3], $row)];
            }
        }

        return [$inside, $outside, $outside === 0 ? [] : $bounds];
    }
}
