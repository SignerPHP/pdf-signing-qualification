<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Performance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SignerPHP\PdfSigningQualification\Performance\Statistics;

final class StatisticsTest extends TestCase
{
    public static function quantiles(): iterable
    {
        yield 'median of an odd count' => [[3.0, 1.0, 2.0], 0.5, 2.0];
        yield 'median of an even count' => [[4.0, 1.0, 3.0, 2.0], 0.5, 2.5];
        yield 'first decile interpolated' => [[10.0, 20.0, 30.0, 40.0, 50.0], 0.1, 14.0];
        yield 'ninth decile interpolated' => [[10.0, 20.0, 30.0, 40.0, 50.0], 0.9, 46.0];
        yield 'single value' => [[7.5], 0.25, 7.5];
    }

    /**
     * @param  list<float>  $values
     */
    #[DataProvider('quantiles')]
    public function test_quantile_interpolates_between_order_statistics(array $values, float $probability, float $expected): void
    {
        self::assertEqualsWithDelta($expected, Statistics::quantile($values, $probability), 1e-12);
    }

    public function test_coefficient_of_variation_uses_the_sample_deviation(): void
    {
        self::assertEqualsWithDelta(0.5, Statistics::coefficientOfVariation([1.0, 2.0, 3.0]), 1e-12);
        self::assertNull(Statistics::coefficientOfVariation([2.0]));
        self::assertNull(Statistics::coefficientOfVariation([0.0, 0.0]));
    }

    public function test_bootstrap_interval_contains_the_median_and_is_reproduced_from_the_seed(): void
    {
        $values = [1.0, 2.0, 2.5, 3.0, 4.0, 5.0, 9.0];
        $interval = Statistics::bootstrapMedianInterval($values, 500, 0.95, new Randomizer(new Mt19937(5)));

        self::assertLessThanOrEqual(3.0, $interval[0]);
        self::assertGreaterThanOrEqual(3.0, $interval[1]);
        self::assertGreaterThanOrEqual(1.0, $interval[0]);
        self::assertLessThanOrEqual(9.0, $interval[1]);
        self::assertSame($interval, Statistics::bootstrapMedianInterval($values, 500, 0.95, new Randomizer(new Mt19937(5))));
    }

    public function test_bootstrap_interval_of_identical_values_has_no_width(): void
    {
        self::assertSame([2.0, 2.0], Statistics::bootstrapMedianInterval([2.0, 2.0, 2.0], 200, 0.95, new Randomizer(new Mt19937(1))));
    }
}
