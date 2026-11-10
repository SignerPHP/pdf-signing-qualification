<?php

declare(strict_types=1);

namespace SignerPHP\PdfSigningQualification\Panel;

final class QuotaSampler
{
    /**
     * @param  array<string, list<string>>  $strataById
     * @return list<string>
     */
    public static function sample(array $strataById, string $seed, int $quotaPerStratum): array
    {
        $drawOrder = [];
        foreach (array_keys($strataById) as $id) {
            $drawOrder[(string) $id] = hash('sha256', "{$seed}:{$id}");
        }
        asort($drawOrder, SORT_STRING);

        $selectedPerStratum = [];
        $selected = [];
        foreach (array_keys($drawOrder) as $id) {
            $id = (string) $id;
            $fillsAQuota = false;
            foreach ($strataById[$id] as $stratum) {
                if (($selectedPerStratum[$stratum] ?? 0) < $quotaPerStratum) {
                    $fillsAQuota = true;
                }
            }
            if (! $fillsAQuota) {
                continue;
            }
            $selected[] = $id;
            foreach ($strataById[$id] as $stratum) {
                $selectedPerStratum[$stratum] = ($selectedPerStratum[$stratum] ?? 0) + 1;
            }
        }

        return $selected;
    }
}
