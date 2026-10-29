<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Metrics\Kpi;
use PHPUnit\Framework\TestCase;

class KpiGrowthTest extends TestCase
{
    public function test_growth_returns_null_when_previous_is_null_or_zero(): void
    {
        $this->assertNull(Kpi::growth(100.0, null));
        $this->assertNull(Kpi::growth(null, 100.0));
        $this->assertNull(Kpi::growth(100.0, 0.0));
        $this->assertNull(Kpi::growth(null, null));
    }

    public function test_growth_calculates_correctly(): void
    {
        // 120 vs 100 -> +20%
        $this->assertSame(0.20, Kpi::growth(120.0, 100.0));

        // 80 vs 100 -> -20%
        $this->assertSame(-0.20, Kpi::growth(80.0, 100.0));

        // Denominator absolute value: current = -50, previous = -100 -> (-50 - (-100)) / |-100| = +50 / 100 = +0.50
        $this->assertSame(0.50, Kpi::growth(-50.0, -100.0));
    }

    public function test_is_good_direction_for_lower_is_better_metrics(): void
    {
        $lowerIsBetterMetrics = [
            'food_cost_pct',
            'waste_pct',
            'cancel_rate',
            'return_rate',
            'labor_pct',
            'prime_cost_pct',
            'expenses',
            'cash_deficit',
        ];

        foreach ($lowerIsBetterMetrics as $metric) {
            // Delta < 0 (cost/rate decreased) -> GOOD (true)
            $this->assertTrue(Kpi::isGoodDirection($metric, -0.05), "Failed asserting delta < 0 is good for {$metric}");
            // Delta > 0 (cost/rate increased) -> BAD (false)
            $this->assertFalse(Kpi::isGoodDirection($metric, 0.05), "Failed asserting delta > 0 is bad for {$metric}");
            // Delta == 0 -> false
            $this->assertFalse(Kpi::isGoodDirection($metric, 0.0), "Failed asserting delta == 0 is false for {$metric}");
        }
    }

    public function test_is_good_direction_for_higher_is_better_metrics(): void
    {
        $higherIsBetterMetrics = [
            'net_sales',
            'gross_sales',
            'orders',
            'net_profit',
            'gross_margin',
            'avg_ticket',
        ];

        foreach ($higherIsBetterMetrics as $metric) {
            // Delta > 0 (sales/profit increased) -> GOOD (true)
            $this->assertTrue(Kpi::isGoodDirection($metric, 500.0), "Failed asserting delta > 0 is good for {$metric}");
            // Delta < 0 (sales/profit decreased) -> BAD (false)
            $this->assertFalse(Kpi::isGoodDirection($metric, -500.0), "Failed asserting delta < 0 is bad for {$metric}");
        }
    }
}
