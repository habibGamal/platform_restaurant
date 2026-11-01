<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Domain\Metrics\KpiValues;

/**
 * Trait providing K01-K25 metric definitions, aliases, and value extraction from KpiValues.
 */
trait ExtractsKpiValues
{
    /**
     * Map of K-codes to bilingual labels from conventions §3.
     *
     * @var array<string, string>
     */
    public const KPI_DEFINITIONS = [
        'K01' => 'عدد الطلبات / Orders',
        'K02' => 'إجمالي المبيعات قبل الخصم / Gross sales',
        'K03' => 'الخصومات / Discounts',
        'K04' => 'صافي المبيعات / Net sales',
        'K05' => 'المرتجعات / Returns',
        'K06' => 'المبيعات / Sales (headline)',
        'K07' => 'متوسط قيمة الطلب / Avg ticket',
        'K08' => 'تكلفة المبيعات / COGS',
        'K09' => 'مجمل الربح / Gross profit',
        'K10' => 'هامش مجمل الربح / Gross margin',
        'K11' => 'المصروفات / Expenses',
        'K11a' => 'تكلفة العمالة / Labor',
        'K12' => 'الهالك / Waste cost',
        'K13' => 'عجز الجرد / Inventory variance',
        'K14' => 'صافي الربح / Net profit',
        'K15' => 'هامش الربح / Net margin',
        'K16' => 'نسبة تكلفة الخامات / Food cost %',
        'K17' => 'نسبة الهالك / Waste %',
        'K18' => 'نسبة العمالة / Labor %',
        'K19' => 'Prime cost %',
        'K20' => 'صافي المشتريات / Net purchases',
        'K21' => 'نسبة الإلغاء / Cancel rate',
        'K22' => 'نسبة المرتجع / Return rate',
        'K23' => 'نسبة تحقيق المستهدف / Target achievement',
        'K24' => 'النمو / Growth',
        'K25' => 'عجز النقدية / Cash deficit',
    ];

    /**
     * Aliases mapping user / model friendly names to standard K-codes.
     *
     * @var array<string, string>
     */
    public const KPI_ALIASES = [
        'orders' => 'K01',
        'orders_count' => 'K01',
        'gross_sales' => 'K02',
        'discounts' => 'K03',
        'net_sales' => 'K04',
        'returns' => 'K05',
        'returns_amount' => 'K05',
        'sales' => 'K06',
        'net_sales_after_returns' => 'K06',
        'avg_ticket' => 'K07',
        'cogs' => 'K08',
        'gross_profit' => 'K09',
        'gross_margin' => 'K10',
        'expenses' => 'K11',
        'expenses_total' => 'K11',
        'labor' => 'K11a',
        'labor_cost' => 'K11a',
        'waste' => 'K12',
        'waste_cost' => 'K12',
        'inventory_variance' => 'K13',
        'inventory_variance_cost' => 'K13',
        'net_profit' => 'K14',
        'profit' => 'K14',
        'net_margin' => 'K15',
        'food_cost_pct' => 'K16',
        'waste_pct' => 'K17',
        'labor_pct' => 'K18',
        'prime_cost_pct' => 'K19',
        'net_purchases' => 'K20',
        'cancel_rate' => 'K21',
        'return_rate' => 'K22',
        'target_achievement' => 'K23',
        'growth' => 'K24',
        'cash_deficit' => 'K25',
        'cash_deficit_total' => 'K25',
    ];

    /**
     * Normalize a list of requested KPI codes or aliases.
     *
     * @param  list<string>|null  $requested
     * @return list<string>
     */
    public function resolveKpiCodes(?array $requested): array
    {
        if ($requested === null || empty($requested)) {
            return ['K01', 'K06', 'K07', 'K09', 'K10', 'K14', 'K15', 'K16'];
        }

        $codes = [];
        foreach ($requested as $item) {
            $normalized = trim($item);
            $upper = strtoupper($normalized);

            if (isset(self::KPI_DEFINITIONS[$upper])) {
                $codes[] = $upper;

                continue;
            }

            $lower = strtolower($normalized);
            if (isset(self::KPI_ALIASES[$lower])) {
                $codes[] = self::KPI_ALIASES[$lower];
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Extract a single numeric KPI value from KpiValues instance.
     */
    public function extractKpi(string $code, KpiValues $values, ?float $allocatedLabor = null): ?float
    {
        $code = strtoupper($code);

        return match ($code) {
            'K01' => (float) $values->orders_count,
            'K02' => $values->gross_sales,
            'K03' => $values->discounts,
            'K04' => $values->net_sales,
            'K05' => $values->returns_amount,
            'K06' => $values->net_sales_after_returns,
            'K07' => $values->avgTicket(),
            'K08' => $values->cogs - $values->returned_cogs_recovered,
            'K09' => $values->gross_profit,
            'K10' => $values->grossMargin(),
            'K11' => $values->expenses_total,
            'K11A' => $values->labor_cost,
            'K12' => $values->waste_cost,
            'K13' => $values->inventory_variance_cost,
            'K14' => $values->net_profit,
            'K15' => $values->netMargin(),
            'K16' => $values->foodCostPct(),
            'K17' => $values->wastePct(),
            'K18' => $values->laborPct($allocatedLabor ?? $values->labor_cost),
            'K19' => $values->primeCostPct($allocatedLabor ?? $values->labor_cost),
            'K20' => $values->netPurchases(),
            'K21' => $values->cancelRate(),
            'K22' => $values->returnRate(),
            'K23' => null,
            'K24' => null,
            'K25' => $values->cash_deficit_total,
            default => null,
        };
    }
}
