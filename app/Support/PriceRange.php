<?php

namespace App\Support;

/**
 * فئة السعر من `price_max` (03 القسم 7.2): budget أقل من 250، mid من 250 لـ 600، premium أكتر من 600.
 *
 * الحدود الافتراضية هي قيم `settings` (price_budget_max و price_mid_max)، وبتتمرّر لما تتغيّر.
 * ملحوظة: التسمية «budget_max» ممكن تتفهم «لحد 250 شامل»، والقسم 7.2 صريح: أقل من 250 budget و250 نفسها mid.
 */
final class PriceRange
{
    public const BUDGET_MAX = 250;

    public const MID_MAX = 600;

    public static function forMaxPrice(?int $priceMax, int $budgetMax = self::BUDGET_MAX, int $midMax = self::MID_MAX): ?string
    {
        if ($priceMax === null) {
            return null;
        }

        return match (true) {
            $priceMax < $budgetMax => 'budget',
            $priceMax <= $midMax => 'mid',
            default => 'premium',
        };
    }
}
