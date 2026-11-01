<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics\QueryMetrics;

final class TableMatchResolver
{
    /**
     * Priority order when multiple aggregate tables satisfy the query.
     */
    private const TABLE_PRIORITY = [
        'agg_branch_daily' => 1,
        'agg_product_daily' => 2,
        'agg_branch_hourly' => 3,
        'agg_shift' => 4,
        'agg_expense_daily' => 5,
        'agg_purchase_daily' => 6,
    ];

    public function __construct(
        private readonly QueryMetricsRegistry $registry,
    ) {}

    /**
     * Resolve the single aggregate table matching all requested measures and group_by dimensions.
     * Returns null if no single table supports the combination.
     *
     * @param  list<string>  $measures
     * @param  list<string>  $groupBy
     */
    public function resolveTable(array $measures, array $groupBy): ?string
    {
        if (empty($measures)) {
            return null;
        }

        // 1. Candidate tables matching ALL requested measures
        /** @var list<string>|null $candidateTables */
        $candidateTables = null;

        foreach ($measures as $measureName) {
            $metric = $this->registry->getMetric($measureName);
            if ($metric === null) {
                return null;
            }

            $tablesForMeasure = $metric->candidateTables();
            if ($candidateTables === null) {
                $candidateTables = $tablesForMeasure;
            } else {
                $candidateTables = array_values(array_intersect($candidateTables, $tablesForMeasure));
            }

            if (empty($candidateTables)) {
                return null;
            }
        }

        // 2. Filter tables that also match ALL requested group_by dimensions
        foreach ($groupBy as $dimName) {
            $dim = $this->registry->getDimension($dimName);
            if ($dim === null) {
                return null;
            }

            $tablesForDim = $dim->candidateTables();
            $candidateTables = array_values(array_intersect($candidateTables, $tablesForDim));

            if (empty($candidateTables)) {
                return null;
            }
        }

        // 3. Pick highest priority table
        usort($candidateTables, function (string $a, string $b): int {
            $pA = self::TABLE_PRIORITY[$a] ?? 99;
            $pB = self::TABLE_PRIORITY[$b] ?? 99;

            return $pA <=> $pB;
        });

        return $candidateTables[0];
    }

    /**
     * List human-readable descriptions of supported combinations for error messages.
     *
     * @return list<string>
     */
    public function supportedCombinationsList(): array
    {
        return [
            'agg_branch_daily: group_by [branch, restaurant, date, week, month, weekday] with K-code sales/cost/profit measures',
            'agg_product_daily: group_by [product, category, branch, date, week, month] with product measures (qty_sold, net_sales, gross_profit, cogs, discounts, waste)',
            'agg_branch_hourly: group_by [hour, weekday, branch, date, week, month] with hourly orders, net_sales, avg_ticket',
            'agg_expense_daily: group_by [expense_category, branch, date, week, month] with expense amount',
            'agg_purchase_daily: group_by [supplier, branch, date, week, month] with purchases amount, invoices_count, net_purchases',
            'agg_shift: group_by [shift, cashier, branch, date, weekday, week, month] with shift orders, net_sales, gross_profit, cash_deficit',
        ];
    }
}
