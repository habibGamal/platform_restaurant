<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\Completeness;
use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Targets\TargetAchievement;
use App\Models\Branch;
use Carbon\CarbonImmutable;

final class OverviewReport
{
    public function __construct(
        public readonly MetricsQuery $query,
    ) {}

    public static function for(MetricsQuery $query): self
    {
        return new self($query);
    }

    /**
     * Row 1: KPI cards data
     *
     * @return array{
     *     sales: array{value: float, comparison: ?float, growth: ?float, sparkline: list<float>},
     *     net_profit: array{value: float, comparison: ?float, growth: ?float, sparkline: list<float>},
     *     net_margin: array{value: ?float, comparison: ?float, growth: ?float, sparkline: list<float>},
     *     orders_count: array{value: int, comparison: ?int, growth: ?float, sparkline: list<int>},
     *     avg_ticket: array{value: ?float, comparison: ?float, growth: ?float, sparkline: list<float>},
     *     expenses: array{value: float, comparison: ?float, growth: ?float, sparkline: list<float>},
     *     net_purchases: array{value: float, comparison: ?float, growth: ?float, sparkline: list<float>},
     *     waste: array{value: float, comparison: ?float, growth: ?float, waste_pct: ?float, sparkline: list<float>},
     * }
     */
    public function kpiCards(): array
    {
        $currentTotals = $this->query->totals();
        $preset = $this->query->filters->preset;
        $comparisonPeriod = $this->query->filters->comparison;

        // If Today view, compare against same weekday last week up to current hour
        if ($preset === PeriodPreset::today && $comparisonPeriod !== null) {
            $cutoff = $this->query->filters->period->cutoffTime ?? CarbonImmutable::now('Africa/Cairo')->format('H:i');
            $compHourly = $this->query->hourlyTotals($comparisonPeriod, $cutoff);

            // Return event time is not available in the current contract.
            $compSales = null;

            $compOrders = $compHourly['orders'];
            $compAvgTicket = $compOrders > 0 ? ((float) $compHourly['net_sales'] / $compOrders) : null;

            $compTotals = $this->query->comparisonTotals();
            $compProfit = $compTotals?->net_profit;
            $compMargin = $compTotals?->netMargin();
            $compExpenses = $compTotals?->expenses_total;
            $compPurchases = $compTotals?->netPurchases();
            $compWaste = $compTotals?->waste_cost;
        } else {
            $compTotals = $this->query->comparisonTotals();
            $compSales = $compTotals?->net_sales_after_returns;
            $compOrders = $compTotals?->orders_count;
            $compAvgTicket = $compTotals?->avgTicket();
            $compProfit = $compTotals?->net_profit;
            $compMargin = $compTotals?->netMargin();
            $compExpenses = $compTotals?->expenses_total;
            $compPurchases = $compTotals?->netPurchases();
            $compWaste = $compTotals?->waste_cost;
        }

        // 14-day sparklines
        $sparklinePeriod = new Period(
            $this->query->filters->period->to->subDays(13),
            $this->query->filters->period->to
        );

        $sparklineRows = $this->query->scoped('agg_branch_daily', $sparklinePeriod)
            ->groupBy('business_date')
            ->selectRaw('
                business_date,
                COALESCE(SUM(orders_count), 0) as orders_count,
                COALESCE(SUM(net_sales_after_returns), 0) as net_sales_after_returns,
                COALESCE(SUM(net_profit), 0) as net_profit,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(expenses_total), 0) as expenses_total,
                COALESCE(SUM(purchases_total) - SUM(purchase_returns_total), 0) as net_purchases,
                COALESCE(SUM(waste_cost), 0) as waste_cost
            ')
            ->get()
            ->keyBy('business_date');

        $sparklines = [
            'sales' => [],
            'profit' => [],
            'margin' => [],
            'orders' => [],
            'avg_ticket' => [],
            'expenses' => [],
            'purchases' => [],
            'waste' => [],
        ];

        foreach ($sparklinePeriod->dates() as $date) {
            $dateStr = $date->toDateString();
            /** @var object|null $row */
            $row = $sparklineRows->get($dateStr);

            $s = (float) ($row->net_sales_after_returns ?? 0.0);
            $p = (float) ($row->net_profit ?? 0.0);
            $o = (int) ($row->orders_count ?? 0);
            $ns = (float) ($row->net_sales ?? 0.0);
            $e = (float) ($row->expenses_total ?? 0.0);
            $np = (float) ($row->net_purchases ?? 0.0);
            $w = (float) ($row->waste_cost ?? 0.0);

            $sparklines['sales'][] = $s;
            $sparklines['profit'][] = $p;
            $sparklines['margin'][] = $s != 0.0 ? round(($p / $s) * 100, 1) : 0.0;
            $sparklines['orders'][] = $o;
            $sparklines['avg_ticket'][] = $o > 0 ? round($ns / $o, 2) : 0.0;
            $sparklines['expenses'][] = $e;
            $sparklines['purchases'][] = $np;
            $sparklines['waste'][] = $w;
        }

        return [
            'sales' => [
                'value' => $currentTotals->net_sales_after_returns,
                'comparison' => $compSales,
                'growth' => Kpi::growth($currentTotals->net_sales_after_returns, $compSales),
                'sparkline' => $sparklines['sales'],
            ],
            'net_profit' => [
                'value' => $currentTotals->net_profit,
                'comparison' => $compProfit,
                'growth' => Kpi::growth($currentTotals->net_profit, $compProfit),
                'sparkline' => $sparklines['profit'],
            ],
            'net_margin' => [
                'value' => $currentTotals->netMargin(),
                'comparison' => $compMargin,
                'growth' => Kpi::growth($currentTotals->netMargin(), $compMargin),
                'sparkline' => $sparklines['margin'],
            ],
            'orders_count' => [
                'value' => $currentTotals->orders_count,
                'comparison' => $compOrders,
                'growth' => Kpi::growth((float) $currentTotals->orders_count, $compOrders !== null ? (float) $compOrders : null),
                'sparkline' => $sparklines['orders'],
            ],
            'avg_ticket' => [
                'value' => $currentTotals->avgTicket(),
                'comparison' => $compAvgTicket,
                'growth' => Kpi::growth($currentTotals->avgTicket(), $compAvgTicket),
                'sparkline' => $sparklines['avg_ticket'],
            ],
            'expenses' => [
                'value' => $currentTotals->expenses_total,
                'comparison' => $compExpenses,
                'growth' => Kpi::growth($currentTotals->expenses_total, $compExpenses),
                'sparkline' => $sparklines['expenses'],
            ],
            'net_purchases' => [
                'value' => $currentTotals->netPurchases(),
                'comparison' => $compPurchases,
                'growth' => Kpi::growth($currentTotals->netPurchases(), $compPurchases),
                'sparkline' => $sparklines['purchases'],
            ],
            'waste' => [
                'value' => $currentTotals->waste_cost,
                'comparison' => $compWaste,
                'growth' => Kpi::growth($currentTotals->waste_cost, $compWaste),
                'waste_pct' => $currentTotals->wastePct(),
                'sparkline' => $sparklines['waste'],
            ],
        ];
    }

    /**
     * Row 2: Sales trend data
     *
     * @return array{
     *     labels: list<string>,
     *     datasets: list<array<string, mixed>>,
     * }
     */
    public function salesTrend(string $mode = 'total'): array
    {
        $granularity = $this->query->filters->granularity;
        $period = $this->query->filters->period;

        if ($mode === 'per_branch') {
            /** @var array<int, array<string, KpiValues>> $branchSeries */
            $branchSeries = $this->query->series($granularity, $period, perBranch: true);

            $branchIds = $this->query->branchIds();
            $branches = Branch::withoutGlobalScopes()
                ->whereIn('id', $branchIds)
                ->get()
                ->keyBy('id');

            // Rank branches by total sales
            $branchTotals = [];
            foreach ($branchIds as $id) {
                $series = $branchSeries[$id] ?? [];
                $branchTotals[$id] = array_sum(array_map(fn (KpiValues $kpi): float => $kpi->net_sales_after_returns, $series));
            }
            arsort($branchTotals);

            $topBranchIds = array_slice(array_keys($branchTotals), 0, 10);
            $otherBranchIds = array_slice(array_keys($branchTotals), 10);

            // Establish labels
            $orderedBuckets = [];
            foreach ($period->dates() as $date) {
                $bucket = $granularity->bucketStart($date)->toDateString();
                $orderedBuckets[$bucket] = true;
            }
            $labels = array_keys($orderedBuckets);

            $datasets = [];
            foreach ($topBranchIds as $bId) {
                $branch = $branches->get($bId);
                $name = $branch !== null ? $branch->name : "فرع {$bId}";
                $series = $branchSeries[$bId] ?? [];

                $data = [];
                foreach ($labels as $bucket) {
                    $kpi = $series[$bucket] ?? KpiValues::zero();
                    $data[] = $kpi->net_sales_after_returns;
                }

                $datasets[] = [
                    'branch_id' => $bId,
                    'label' => $name,
                    'data' => $data,
                ];
            }

            if (! empty($otherBranchIds)) {
                $otherData = [];
                foreach ($labels as $bucket) {
                    $sum = 0.0;
                    foreach ($otherBranchIds as $oId) {
                        $kpi = $branchSeries[$oId][$bucket] ?? KpiValues::zero();
                        $sum += $kpi->net_sales_after_returns;
                    }
                    $otherData[] = $sum;
                }

                $datasets[] = [
                    'branch_id' => 0,
                    'label' => 'أخرى',
                    'data' => $otherData,
                ];
            }

            return [
                'labels' => $labels,
                'datasets' => $datasets,
            ];
        }

        // Total mode
        /** @var array<string, KpiValues> $currentSeries */
        $currentSeries = $this->query->series($granularity, $period, perBranch: false);
        $labels = array_keys($currentSeries);
        $currentData = array_map(fn (KpiValues $kpi): float => $kpi->net_sales_after_returns, array_values($currentSeries));

        $datasets = [
            [
                'label' => 'الفترة الحالية',
                'data' => $currentData,
                'is_current' => true,
            ],
        ];

        if ($this->query->filters->comparison !== null) {
            /** @var array<string, KpiValues> $compSeries */
            $compSeries = $this->query->series($granularity, $this->query->filters->comparison, perBranch: false);
            $compData = array_map(fn (KpiValues $kpi): float => $kpi->net_sales_after_returns, array_values($compSeries));

            // Align lengths if comparison has different count
            $compData = array_slice($compData, 0, count($labels));
            while (count($compData) < count($labels)) {
                $compData[] = 0.0;
            }

            $datasets[] = [
                'label' => 'فترة المقارنة',
                'data' => $compData,
                'is_current' => false,
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Row 3: Branch table data
     *
     * @return array{
     *     branches: array<int|string, array<string, mixed>>,
     *     totals: ?array<string, mixed>,
     * }
     */
    public function branchTable(?string $sortColumn = null, ?string $sortDirection = 'asc'): array
    {
        $branchIds = $this->query->branchIds();
        $period = $this->query->filters->period;
        $comparison = $this->query->filters->comparison;

        $branchTotals = $this->query->byBranch($period);
        $compBranchTotals = $comparison !== null ? $this->query->byBranch($comparison) : [];

        $completeness = Completeness::for($this->query, $period);

        $today = CarbonImmutable::now('Africa/Cairo');
        $targetStatuses = TargetAchievement::for($this->query, 'net_sales', $today);
        $targetsByBranch = [];
        foreach ($targetStatuses as $status) {
            $targetsByBranch[$status->branchId] = $status;
        }

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        $canSeeGroup = $this->query->canSeeGroup();
        $canSeeOtherBranchNames = $this->query->canSeeOtherBranchNames();

        // Calculate group net margin for color scale
        $groupTotals = $this->query->totals($period);
        $groupNetMargin = $groupTotals->netMargin() ?? 0.0;

        $rows = [];
        $index = 1;

        foreach ($branchIds as $branchId) {
            $branch = $branches->get($branchId);
            $kpi = $branchTotals[$branchId] ?? KpiValues::zero();
            $compKpi = $compBranchTotals[$branchId] ?? null;

            // Branch name anonymization if not permitted
            if (! $canSeeOtherBranchNames && ! $canSeeGroup) {
                $name = $branch !== null ? $branch->name : "فرع {$branchId}";
            } elseif (! $canSeeOtherBranchNames && count($branchIds) > 1) {
                $name = "فرع {$index}";
            } else {
                $name = $branch !== null ? $branch->name : "فرع {$branchId}";
            }

            // Completeness status
            if ($period->days() === 1) {
                $status = $completeness->statusFor($branchId, $period->from->toDateString());
            } else {
                $status = $completeness->isBranchComplete($branchId) ? 'complete' : 'closed';
            }

            $sales = $kpi->net_sales_after_returns;
            $compSales = $compKpi?->net_sales_after_returns;
            $growth = Kpi::growth($sales, $compSales);

            $netMargin = $kpi->netMargin();
            if ($netMargin === null) {
                $marginColor = 'gray';
            } elseif ($netMargin >= $groupNetMargin) {
                $marginColor = 'success';
            } elseif ($netMargin >= 0.0) {
                $marginColor = 'warning';
            } else {
                $marginColor = 'danger';
            }

            $targetStatus = $targetsByBranch[$branchId] ?? null;
            $targetAchievement = $targetStatus !== null ? ($targetStatus->achievementToDate ?? $targetStatus->monthAchievement) : null;

            $rows[$branchId] = [
                'id' => $branchId,
                'branch_id' => $branchId,
                'branch' => $name,
                'branch_name' => $name,
                'completeness_status' => $status,
                'sales' => $sales,
                'growth' => $growth,
                'orders' => $kpi->orders_count,
                'avg_ticket' => $kpi->avgTicket(),
                'gross_profit' => $kpi->gross_profit,
                'net_profit' => $kpi->net_profit,
                'net_margin' => $netMargin,
                'net_margin_color' => $marginColor,
                'food_cost_pct' => $kpi->foodCostPct(),
                'waste_pct' => $kpi->wastePct(),
                'expenses' => $kpi->expenses_total,
                'net_purchases' => $kpi->netPurchases(),
                'target_pct' => $targetAchievement,
                'target_status' => $targetStatus !== null ? $targetStatus->status : 'no_target',
            ];

            $index++;
        }

        // Sorting branch rows
        if ($sortColumn !== null && ! empty($rows)) {
            $isDesc = strtolower($sortDirection ?? 'asc') === 'desc';
            uasort($rows, function (array $a, array $b) use ($sortColumn, $isDesc): int {
                $valA = $a[$sortColumn] ?? null;
                $valB = $b[$sortColumn] ?? null;

                if ($valA === $valB) {
                    return 0;
                }

                if ($valA === null) {
                    return 1;
                }

                if ($valB === null) {
                    return -1;
                }

                $cmp = $valA <=> $valB;

                return $isDesc ? -$cmp : $cmp;
            });
        }

        // Totals row (only if user has analytics.view_group)
        $totalsRow = null;
        if ($canSeeGroup) {
            $compGroupKpi = $comparison !== null ? $this->query->totals($comparison) : null;
            $groupGrowth = Kpi::growth($groupTotals->net_sales_after_returns, $compGroupKpi?->net_sales_after_returns);

            $totalsRow = [
                'id' => 'total',
                'branch_id' => 0,
                'branch' => 'المجموع',
                'branch_name' => 'المجموع',
                'completeness_status' => null,
                'sales' => $groupTotals->net_sales_after_returns,
                'growth' => $groupGrowth,
                'orders' => $groupTotals->orders_count,
                'avg_ticket' => $groupTotals->avgTicket(),
                'gross_profit' => $groupTotals->gross_profit,
                'net_profit' => $groupTotals->net_profit,
                'net_margin' => $groupTotals->netMargin(),
                'net_margin_color' => ($groupTotals->netMargin() ?? 0.0) >= 0.0 ? 'success' : 'danger',
                'food_cost_pct' => $groupTotals->foodCostPct(),
                'waste_pct' => $groupTotals->wastePct(),
                'expenses' => $groupTotals->expenses_total,
                'net_purchases' => $groupTotals->netPurchases(),
                'target_pct' => null,
                'target_status' => null,
            ];
        }

        return [
            'branches' => $rows,
            'totals' => $totalsRow,
        ];
    }

    /**
     * Row 4: Profit bridge (waterfall data)
     *
     * @return array{
     *     sales: float,
     *     cogs: float,
     *     gross_profit: float,
     *     expenses: float,
     *     waste: float,
     *     inventory_variance: float,
     *     net_profit: float,
     * }
     */
    public function profitBridge(): array
    {
        $totals = $this->query->totals($this->query->filters->period);
        $cogs = $totals->cogs - $totals->returned_cogs_recovered;

        return [
            'sales' => $totals->net_sales_after_returns,
            'cogs' => $cogs,
            'gross_profit' => $totals->gross_profit,
            'expenses' => $totals->expenses_total,
            'waste' => $totals->waste_cost,
            'inventory_variance' => $totals->inventory_variance_cost,
            'net_profit' => $totals->net_profit,
        ];
    }

    /**
     * Row 4: Expense mix by category
     *
     * @return list<array{category: string, label: string, amount: float}>
     */
    public function expenseMix(): array
    {
        $rows = $this->query->scoped('agg_expense_daily', $this->query->filters->period)
            ->groupBy('category')
            ->selectRaw('category, COALESCE(SUM(amount), 0) as total')
            ->orderByDesc('total')
            ->get();

        $categoryLabels = [
            'labor' => 'عمالة',
            'rent' => 'إيجار',
            'utilities' => 'مرافق',
            'maintenance' => 'صيانة',
            'marketing' => 'تسويق',
            'supplies' => 'مستلزمات',
            'operational' => 'تشغيلية',
            'other' => 'أخرى',
        ];

        $items = [];
        if ($rows->isNotEmpty()) {
            foreach ($rows as $row) {
                $cat = (string) $row->category;
                $items[] = [
                    'category' => $cat,
                    'label' => $categoryLabels[$cat] ?? $cat,
                    'amount' => (float) $row->total,
                ];
            }
        } else {
            $totals = $this->query->totals($this->query->filters->period);
            if ($totals->labor_cost > 0) {
                $items[] = ['category' => 'labor', 'label' => 'عمالة', 'amount' => $totals->labor_cost];
            }
            if ($totals->rent_cost > 0) {
                $items[] = ['category' => 'rent', 'label' => 'إيجار', 'amount' => $totals->rent_cost];
            }
            if ($totals->other_expenses > 0) {
                $items[] = ['category' => 'other', 'label' => 'أخرى', 'amount' => $totals->other_expenses];
            }
        }

        return $items;
    }

    /**
     * Row 4: Sales by channel (sales_by_type)
     *
     * @return list<array{type: string, label: string, sales: float, orders: int}>
     */
    public function salesByChannel(): array
    {
        $rows = $this->query->scoped('agg_branch_daily', $this->query->filters->period)
            ->whereNotNull('sales_by_type')
            ->pluck('sales_by_type');

        $typeLabels = [
            'dine_in' => 'صالة (Dine-in)',
            'takeaway' => 'سفري (Takeaway)',
            'delivery' => 'توصيل (Delivery)',
            'talabat' => 'طلبات (Talabat)',
            'web' => 'أونلاين (Web)',
        ];

        $aggregated = [];
        foreach ($rows as $json) {
            $data = is_string($json) ? json_decode($json, true) : (array) $json;
            if (! is_array($data)) {
                continue;
            }
            foreach ($data as $type => $info) {
                $typeStr = (string) $type;
                if (! isset($aggregated[$typeStr])) {
                    $aggregated[$typeStr] = ['sales' => 0.0, 'orders' => 0];
                }
                if (is_array($info)) {
                    $aggregated[$typeStr]['sales'] += (float) ($info['net_sales'] ?? 0.0);
                    $aggregated[$typeStr]['orders'] += (int) ($info['orders'] ?? 0);
                }
            }
        }

        $result = [];
        foreach ($aggregated as $type => $values) {
            $result[] = [
                'type' => $type,
                'label' => $typeLabels[$type] ?? ucfirst($type),
                'sales' => $values['sales'],
                'orders' => $values['orders'],
            ];
        }

        usort($result, fn (array $a, array $b): int => $b['sales'] <=> $a['sales']);

        return $result;
    }

    /**
     * Row 4: Data freshness and sync health
     *
     * @return array{
     *     summary: array{complete: int, total: int},
     *     branches: list<array{
     *         id: int,
     *         name: string,
     *         last_sync: ?string,
     *         last_sync_time: ?string,
     *         diff_minutes: ?int,
     *         is_delayed: bool,
     *         status: string,
     *         pending_count: int,
     *         failed_count: int,
     *     }>,
     * }
     */
    public function dataFreshness(): array
    {
        $branchIds = $this->query->branchIds();
        $completeness = Completeness::for($this->query, $this->query->filters->period);
        $summary = $completeness->summary();

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get();

        $now = CarbonImmutable::now('Africa/Cairo');
        $items = [];

        foreach ($branches as $branch) {
            $lastSync = collect([$branch->last_seen_at, $branch->last_batch_at, $branch->last_heartbeat_at])
                ->filter()
                ->max();

            $lastSyncCarbon = $lastSync ? CarbonImmutable::parse($lastSync)->setTimezone('Africa/Cairo') : null;
            $diffMinutes = $lastSyncCarbon ? (int) $lastSyncCarbon->diffInMinutes($now) : null;

            $isDelayed = ($lastSyncCarbon === null || $diffMinutes > 15);
            $status = ($lastSyncCarbon === null) ? 'offline' : ($isDelayed ? 'delayed' : 'online');

            $items[] = [
                'id' => $branch->id,
                'name' => $branch->name,
                'last_sync' => $lastSyncCarbon?->diffForHumans($now),
                'last_sync_time' => $lastSyncCarbon?->format('H:i'),
                'diff_minutes' => $diffMinutes,
                'is_delayed' => $isDelayed,
                'status' => $status,
                'pending_count' => (int) $branch->pending_count,
                'failed_count' => (int) $branch->failed_count,
            ];
        }

        return [
            'summary' => $summary,
            'branches' => $items,
        ];
    }
}
