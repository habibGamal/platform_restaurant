<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Metrics\KpiValues;
use PHPUnit\Framework\TestCase;

class KpiValuesTest extends TestCase
{
    public function test_zero_returns_all_zero_values(): void
    {
        $kpi = KpiValues::zero();

        $this->assertSame(0, $kpi->orders_count);
        $this->assertSame(0, $kpi->cancelled_count);
        $this->assertSame(0.0, $kpi->gross_sales);
        $this->assertSame(0.0, $kpi->net_sales);
        $this->assertSame(0.0, $kpi->net_sales_after_returns);
        $this->assertSame(0.0, $kpi->gross_profit);
        $this->assertSame(0.0, $kpi->net_profit);
        $this->assertSame(0, $kpi->days_with_data);

        // Division by zero on all derived ratios
        $this->assertNull($kpi->avgTicket());
        $this->assertNull($kpi->grossMargin());
        $this->assertNull($kpi->netMargin());
        $this->assertNull($kpi->foodCostPct());
        $this->assertNull($kpi->wastePct());
        $this->assertNull($kpi->wasteShareOfUsage());
        $this->assertNull($kpi->laborPct(100.0));
        $this->assertNull($kpi->primeCostPct(100.0));
        $this->assertNull($kpi->cancelRate());
        $this->assertNull($kpi->returnRate());
        $this->assertSame(0.0, $kpi->netPurchases());
    }

    public function test_derived_formulas_with_valid_numbers(): void
    {
        $kpi = new KpiValues(
            orders_count: 50,
            cancelled_count: 5,
            gross_sales: 60000.0,
            discounts: 2000.0,
            tax: 7000.0,
            service: 4000.0,
            net_sales: 55000.0,
            returns_amount: 5000.0,
            net_sales_after_returns: 50000.0,
            cogs: 18000.0,
            returned_cogs_recovered: 3000.0,
            gross_profit: 35000.0,
            expenses_total: 10000.0,
            labor_cost: 6000.0,
            rent_cost: 3000.0,
            other_expenses: 1000.0,
            waste_cost: 2000.0,
            inventory_variance_cost: 500.0,
            purchases_total: 25000.0,
            purchase_returns_total: 4000.0,
            net_profit: 22500.0,
            shifts_count: 2,
            cash_deficit_total: 150.0,
            days_with_data: 5,
        );

        // K07: avgTicket = net_sales / orders_count = 55000 / 50 = 1100.0
        $this->assertSame(1100.0, $kpi->avgTicket());

        // K10: grossMargin = gross_profit / net_sales_after_returns = 35000 / 50000 = 0.70
        $this->assertSame(0.70, $kpi->grossMargin());

        // K15: netMargin = net_profit / net_sales_after_returns = 22500 / 50000 = 0.45
        $this->assertSame(0.45, $kpi->netMargin());

        // K16: foodCostPct = (cogs - returned_cogs_recovered) / net_sales_after_returns = (18000 - 3000) / 50000 = 0.30
        $this->assertSame(0.30, $kpi->foodCostPct());

        // K17: wastePct = waste_cost / net_sales_after_returns = 2000 / 50000 = 0.04
        $this->assertSame(0.04, $kpi->wastePct());

        // K17 secondary: wasteShareOfUsage = waste_cost / ((cogs - returned_cogs_recovered) + waste_cost) = 2000 / (15000 + 2000) = 2000 / 17000
        $this->assertEqualsWithDelta(2000.0 / 17000.0, $kpi->wasteShareOfUsage(), 0.0001);

        // K18: laborPct = allocatedLabor / net_sales_after_returns = 7500 / 50000 = 0.15
        $this->assertSame(0.15, $kpi->laborPct(7500.0));

        // K19: primeCostPct = ((cogs - returned_cogs_recovered) + allocatedLabor) / net_sales_after_returns = (15000 + 7500) / 50000 = 0.45
        $this->assertSame(0.45, $kpi->primeCostPct(7500.0));

        // K20: netPurchases = purchases_total - purchase_returns_total = 25000 - 4000 = 21000.0
        $this->assertSame(21000.0, $kpi->netPurchases());

        // K21: cancelRate = cancelled_count / (orders_count + cancelled_count) = 5 / (50 + 5) = 5 / 55
        $this->assertEqualsWithDelta(5.0 / 55.0, $kpi->cancelRate(), 0.0001);

        // K22: returnRate = returns_amount / net_sales = 5000 / 55000 = 1 / 11
        $this->assertEqualsWithDelta(5000.0 / 55000.0, $kpi->returnRate(), 0.0001);
    }

    public function test_division_by_zero_returns_null_for_all_ratios(): void
    {
        $kpi = new KpiValues(
            orders_count: 0,
            cancelled_count: 0,
            net_sales: 0.0,
            net_sales_after_returns: 0.0,
            cogs: 0.0,
            returned_cogs_recovered: 0.0,
            waste_cost: 0.0,
        );

        $this->assertNull($kpi->avgTicket());
        $this->assertNull($kpi->grossMargin());
        $this->assertNull($kpi->netMargin());
        $this->assertNull($kpi->foodCostPct());
        $this->assertNull($kpi->wastePct());
        $this->assertNull($kpi->wasteShareOfUsage());
        $this->assertNull($kpi->laborPct(500.0));
        $this->assertNull($kpi->primeCostPct(500.0));
        $this->assertNull($kpi->cancelRate());
        $this->assertNull($kpi->returnRate());
    }

    public function test_plus_combines_base_columns(): void
    {
        $a = new KpiValues(
            orders_count: 10,
            cancelled_count: 1,
            gross_sales: 1000.0,
            net_sales: 900.0,
            net_sales_after_returns: 800.0,
            cogs: 300.0,
            days_with_data: 2,
        );

        $b = new KpiValues(
            orders_count: 20,
            cancelled_count: 2,
            gross_sales: 2000.0,
            net_sales: 1800.0,
            net_sales_after_returns: 1600.0,
            cogs: 600.0,
            days_with_data: 3,
        );

        $sum = $a->plus($b);

        $this->assertSame(30, $sum->orders_count);
        $this->assertSame(3, $sum->cancelled_count);
        $this->assertSame(3000.0, $sum->gross_sales);
        $this->assertSame(2700.0, $sum->net_sales);
        $this->assertSame(2400.0, $sum->net_sales_after_returns);
        $this->assertSame(900.0, $sum->cogs);
        $this->assertSame(5, $sum->days_with_data);
    }

    public function test_from_row_with_object_and_array(): void
    {
        $arrayData = [
            'orders_count' => 15,
            'net_sales' => 1500.50,
            'net_sales_after_returns' => 1400.00,
            'cogs' => 450.00,
            'business_date' => '2026-10-25',
        ];

        $kpiFromArray = KpiValues::fromRow($arrayData);
        $this->assertSame(15, $kpiFromArray->orders_count);
        $this->assertSame(1500.50, $kpiFromArray->net_sales);
        $this->assertSame(1, $kpiFromArray->days_with_data);

        $objectData = (object) [
            'orders_count' => 30,
            'net_sales' => 3000.00,
            'days_with_data' => 4,
        ];

        $kpiFromObject = KpiValues::fromRow($objectData);
        $this->assertSame(30, $kpiFromObject->orders_count);
        $this->assertSame(3000.00, $kpiFromObject->net_sales);
        $this->assertSame(4, $kpiFromObject->days_with_data);
    }
}
