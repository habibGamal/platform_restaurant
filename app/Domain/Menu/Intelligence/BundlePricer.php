<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

/** The combo discount ladder: the largest discount whose 5-EGP-rounded price still keeps the margin floor. */
final class BundlePricer
{
    /** @return array{list_price: float, suggested_price: float, cost: float, margin_pct: float, discount_pct: float}|null */
    public static function price(float $listPrice, float $cost, float $floor): ?array
    {
        foreach ([0.15, 0.12, 0.10, 0.08, 0.05] as $discount) {
            $price = $listPrice * (1 - $discount);
            if (($price - $cost) / $price < $floor) {
                continue;
            }
            $price = floor($price / 5) * 5;
            if ($price <= $cost || ($price - $cost) / $price < $floor) {
                continue;
            }

            return ['list_price' => $listPrice, 'suggested_price' => $price, 'cost' => $cost, 'margin_pct' => ($price - $cost) / $price, 'discount_pct' => 1 - $price / $listPrice];
        }

        return null;
    }
}
