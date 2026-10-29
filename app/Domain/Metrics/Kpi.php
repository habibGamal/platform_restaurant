<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

final class Kpi
{
    /**
     * K24: Growth = (current - previous) / |previous|.
     * Returns null when previous is null or 0.
     */
    public static function growth(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null || $previous == 0.0) {
            return null;
        }

        return ($current - $previous) / abs($previous);
    }

    /**
     * Determine whether the delta is in a favorable direction for the given KPI.
     * Lower is better for: food_cost_pct, waste_pct, cancel_rate, return_rate, labor_pct, prime_cost_pct, expenses, cash_deficit.
     */
    public static function isGoodDirection(string $kpi, float $delta): bool
    {
        $lowerIsBetter = in_array(strtolower($kpi), [
            'food_cost_pct',
            'food_cost',
            'waste_pct',
            'waste_cost',
            'cancel_rate',
            'return_rate',
            'labor_pct',
            'labor_cost',
            'prime_cost_pct',
            'expenses',
            'expenses_total',
            'cash_deficit',
            'cash_deficit_total',
            'inventory_variance_cost',
        ], true);

        return $lowerIsBetter ? ($delta < 0) : ($delta > 0);
    }
}
