<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Performance;

use InvalidArgumentException;
use Random\Randomizer;

final class Statistics
{
    /**
     * @param  list<float>  $values
     */
    public static function quantile(array $values, float $probability): float
    {
        if ($values === []) {
            throw new InvalidArgumentException('The quantile of no value is undefined');
        }
        sort($values);
        $position = ($probability * (count($values) - 1));
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $values[$lower] + ($position - $lower) * ($values[$upper] - $values[$lower]);
    }

    /**
     * @param  list<float>  $values
     */
    public static function median(array $values): float
    {
        return self::quantile($values, 0.5);
    }

    /**
     * @param  list<float>  $values
     */
    public static function coefficientOfVariation(array $values): ?float
    {
        $count = count($values);
        $mean = $count === 0 ? 0.0 : array_sum($values) / $count;
        if ($count < 2 || $mean <= 0.0) {
            return null;
        }
        $squares = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values));

        return sqrt($squares / ($count - 1)) / $mean;
    }

    /**
     * @param  list<float>  $values
     * @return array{float, float}
     */
    public static function bootstrapMedianInterval(array $values, int $resamples, float $confidence, Randomizer $randomizer): array
    {
        $count = count($values);
        if ($count === 0) {
            throw new InvalidArgumentException('The interval of the median of no value is undefined');
        }
        $medians = [];
        for ($resample = 0; $resample < $resamples; $resample++) {
            $drawn = [];
            for ($index = 0; $index < $count; $index++) {
                $drawn[] = $values[$randomizer->getInt(0, $count - 1)];
            }
            $medians[] = self::median($drawn);
        }

        return [self::quantile($medians, (1 - $confidence) / 2), self::quantile($medians, (1 + $confidence) / 2)];
    }
}
