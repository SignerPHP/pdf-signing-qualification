<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Tests\Panel;

use PHPUnit\Framework\TestCase;
use SignerPHP\PdfSigningQualification\Panel\QuotaSampler;

final class QuotaSamplerTest extends TestCase
{
    /**
     * @return array<string, list<string>>
     */
    private static function pool(): array
    {
        $pool = [];
        for ($index = 0; $index < 40; $index++) {
            $pool["common:{$index}.pdf"] = ['classic'];
        }
        $pool['rare:a.pdf'] = ['classic', 'forms'];
        $pool['rare:b.pdf'] = ['stream', 'forms'];

        return $pool;
    }

    /**
     * @param  list<string>  $selected
     * @return array<string, int>
     */
    private static function countPerStratum(array $selected): array
    {
        $counts = [];
        foreach ($selected as $id) {
            foreach (self::pool()[$id] as $stratum) {
                $counts[$stratum] = ($counts[$stratum] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    public function test_every_stratum_reaches_the_quota_or_takes_all_its_candidates(): void
    {
        $selected = QuotaSampler::sample(self::pool(), 'seed', 5);

        $counts = self::countPerStratum($selected);
        self::assertGreaterThanOrEqual(5, $counts['classic']);
        self::assertSame(2, $counts['forms']);
        self::assertSame(1, $counts['stream']);
        self::assertContains('rare:a.pdf', $selected);
        self::assertContains('rare:b.pdf', $selected);
    }

    public function test_a_candidate_is_drawn_only_while_one_of_its_strata_is_below_the_quota(): void
    {
        $selected = QuotaSampler::sample(self::pool(), 'seed', 5);

        self::assertLessThanOrEqual(5 + 2, count($selected));
    }

    public function test_same_seed_draws_the_same_inputs_in_any_declaration_order(): void
    {
        $reversed = array_reverse(self::pool(), true);

        self::assertSame(QuotaSampler::sample(self::pool(), 'seed', 5), QuotaSampler::sample($reversed, 'seed', 5));
    }

    public function test_draw_order_follows_the_hash_of_the_seed_and_the_identifier(): void
    {
        $pool = ['a:1.pdf' => ['classic'], 'a:2.pdf' => ['classic'], 'a:3.pdf' => ['classic']];
        $expected = array_keys($pool);
        usort($expected, static fn (string $left, string $right): int => strcmp(hash('sha256', "panel:{$left}"), hash('sha256', "panel:{$right}")));

        self::assertSame([$expected[0]], QuotaSampler::sample($pool, 'panel', 1));
    }

    public function test_another_seed_can_draw_other_inputs(): void
    {
        $draws = [];
        foreach (['one', 'two', 'three', 'four'] as $seed) {
            $draws[] = implode(',', QuotaSampler::sample(self::pool(), $seed, 5));
        }

        self::assertGreaterThan(1, count(array_unique($draws)));
    }
}
