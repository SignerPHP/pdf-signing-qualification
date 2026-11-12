<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Validation;

use RuntimeException;

final class Raster
{
    private const int BYTES_PER_PIXEL = 3;

    private const int HEADER_BYTES = 64;

    private function __construct(
        public readonly int $width,
        public readonly int $height,
        private readonly string $path,
        private readonly int $pixelOffset,
    ) {}

    public static function open(string $path): ?self
    {
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            return null;
        }
        $header = fread($handle, self::HEADER_BYTES);
        fclose($handle);
        if (! is_string($header) || preg_match('/\AP6\s+(\d+)\s+(\d+)\s+255\s/', $header, $match) !== 1) {
            return null;
        }

        $width = (int) $match[1];
        $height = (int) $match[2];
        $pixelOffset = strlen($match[0]);
        if ($width === 0 || $height === 0 || filesize($path) !== $pixelOffset + $width * $height * self::BYTES_PER_PIXEL) {
            return null;
        }

        return new self($width, $height, $path, $pixelOffset);
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

        $ownHandle = $this->pixels();
        $otherHandle = $other->pixels();
        $rowBytes = $this->width * self::BYTES_PER_PIXEL;
        $inside = 0;
        $outside = 0;
        $bounds = [PHP_INT_MAX, PHP_INT_MAX, PHP_INT_MIN, PHP_INT_MIN];
        try {
            for ($row = 0; $row < $this->height; $row++) {
                $ownRow = (string) fread($ownHandle, $rowBytes);
                $otherRow = (string) fread($otherHandle, $rowBytes);
                if (strlen($ownRow) !== $rowBytes || strlen($otherRow) !== $rowBytes) {
                    throw new RuntimeException("Unable to read row {$row} of {$this->path} or {$other->path}");
                }
                if ($ownRow === $otherRow) {
                    continue;
                }
                for ($column = 0; $column < $this->width; $column++) {
                    $offset = $column * self::BYTES_PER_PIXEL;
                    if (substr($ownRow, $offset, self::BYTES_PER_PIXEL) === substr($otherRow, $offset, self::BYTES_PER_PIXEL)) {
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
        } finally {
            fclose($ownHandle);
            fclose($otherHandle);
        }

        return [$inside, $outside, $outside === 0 ? [] : $bounds];
    }

    /**
     * @return resource
     */
    private function pixels()
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false || fseek($handle, $this->pixelOffset) !== 0) {
            throw new RuntimeException("Unable to read the rendering {$this->path}");
        }

        return $handle;
    }
}
