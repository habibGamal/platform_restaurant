<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\AllocatedCost;
use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Reports\ProfitabilityReport\BranchProfitabilityData;
use App\Domain\Metrics\Reports\ProfitabilityReport\ProfitabilityMetricComparison;
use App\Domain\Metrics\Reports\ProfitabilityReport\ProfitabilityReportData;
use App\Models\Branch;
use App\Support\BranchColor;

final class ProfitabilityReport
{
    private ?ProfitabilityReportData $cachedData = null;

    public function __construct(
        public readonly MetricsQuery $query,
        public readonly string $laborBasis = 'paid',
        public readonly bool $excludeInventoryVariance = false,
    ) {}

    public static function for(
        MetricsQuery $query,
        string $laborBasis = 'paid',
        bool $excludeInventoryVariance = false,
    ): self {
        return new self($query, $laborBasis, $excludeInventoryVariance);
    }

    public function data(): ProfitabilityReportData
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        $filters = $this->query->filters();
        $period = $filters->period;
        $comparisonPeriod = $filters->comparison;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            $this->cachedData = new ProfitabilityReportData(
                period: $period,
                comparisonPeriod: $comparisonPeriod,
                laborBasis: $this->laborBasis,
                excludeInventoryVariance: $this->excludeInventoryVariance,
                branches: [],
                group: null,
                stocktakingNotes: [],
                missingExpenseBranches: [],
            );

            return $this->cachedData;
        }

        $user = $this->query->user();
        $userAccessibleBranchIds = $user->branches()->pluck('branches.id')->map(fn ($id): int => (int) $id)->all();
        $canSeeOtherBranchNames = $this->query->canSeeOtherBranchNames();
        $canSeeGroup = $this->query->canSeeGroup();

        $branchModels = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        // 1. Fetch KPI values from agg_branch_daily
        $currentKpis = $this->query->byBranch($period);
        $comparisonKpis = $comparisonPeriod !== null ? $this->query->byBranch($comparisonPeriod) : [];

        // 2. Fetch stocktaking dates in the current period
        $stocktakingRows = $this->query->scoped('agg_branch_daily', $period)
            ->where('inventory_variance_cost', '!=', 0)
            ->select('branch_id', 'business_date', 'inventory_variance_cost')
            ->orderBy('business_date')
            ->get();

        $branchStocktakings = [];
        $allStocktakingDates = [];
        foreach ($stocktakingRows as $row) {
            $bId = (int) $row->branch_id;
            $bDate = (string) $row->business_date;
            $branchStocktakings[$bId][] = $bDate;
            $allStocktakingDates[$bDate] = true;
        }

        $stocktakingNotes = [];
        if (! empty($allStocktakingDates)) {
            $datesList = implode('، ', array_keys($allStocktakingDates));
            $stocktakingNotes[] = "يتضمن عجز جرد بتاريخ {$datesList}";
        }

        $branchesData = [];
        $missingExpenseBranches = [];

        foreach ($branchIds as $branchId) {
            $model = $branchModels->get($branchId);
            $rawName = $model !== null ? $model->name : "الفرع #{$branchId}";

            if (! $canSeeOtherBranchNames && ! in_array($branchId, $userAccessibleBranchIds, true)) {
                $branchName = "فرع #{$branchId}";
            } else {
                $branchName = $rawName;
            }

            $currentKpi = $currentKpis[$branchId] ?? KpiValues::zero();
            $compKpi = $comparisonKpis[$branchId] ?? null;

            $branchData = $this->buildBranchData(
                branchId: $branchId,
                branchName: $branchName,
                kpi: $currentKpi,
                period: $period,
                stocktakingDates: $branchStocktakings[$branchId] ?? [],
                compKpi: $compKpi,
                compPeriod: $comparisonPeriod,
            );

            $branchesData[$branchId] = $branchData;

            if ($branchData->hasNoExpenses) {
                $missingExpenseBranches[] = $branchName;
            }
        }

        // 3. Group summary (only if canSeeGroup is true)
        $groupData = null;
        if ($canSeeGroup) {
            $groupData = $this->buildGroupData(
                branchesData: $branchesData,
                period: $period,
                comparisonPeriod: $comparisonPeriod,
                branchIds: $branchIds,
            );
        }

        $this->cachedData = new ProfitabilityReportData(
            period: $period,
            comparisonPeriod: $comparisonPeriod,
            laborBasis: $this->laborBasis,
            excludeInventoryVariance: $this->excludeInventoryVariance,
            branches: $branchesData,
            group: $groupData,
            stocktakingNotes: $stocktakingNotes,
            missingExpenseBranches: $missingExpenseBranches,
        );

        return $this->cachedData;
    }

    /**
     * @param  list<string>  $stocktakingDates
     */
    private function buildBranchData(
        int $branchId,
        string $branchName,
        KpiValues $kpi,
        Period $period,
        array $stocktakingDates,
        ?KpiValues $compKpi,
        ?Period $compPeriod,
    ): BranchProfitabilityData {
        $sales = round($kpi->net_sales_after_returns, 2);
        $cogs = round($kpi->cogs - $kpi->returned_cogs_recovered, 2);
        $grossProfit = round($sales - $cogs, 2);
        $grossMargin = $sales > 0 ? ($grossProfit / $sales) : null;

        if ($this->laborBasis === 'allocated') {
            $laborCost = round(AllocatedCost::labor([$branchId], $period), 2);
            $rentCost = round(AllocatedCost::rent([$branchId], $period), 2);
        } else {
            $laborCost = round($kpi->labor_cost, 2);
            $rentCost = round($kpi->rent_cost, 2);
        }

        $otherExpenses = round($kpi->other_expenses, 2);

        if ($this->laborBasis === 'allocated') {
            $totalExpenses = round($laborCost + $rentCost + $otherExpenses, 2);
        } else {
            $totalExpenses = round($kpi->expenses_total, 2);
        }

        $wasteCost = round($kpi->waste_cost, 2);
        $inventoryVarianceCost = $this->excludeInventoryVariance ? 0.0 : round($kpi->inventory_variance_cost, 2);

        $netProfit = round($grossProfit - $totalExpenses - $wasteCost - $inventoryVarianceCost, 2);
        $netMargin = $sales > 0 ? ($netProfit / $sales) : null;

        $cogsPct = $sales > 0 ? ($cogs / $sales) : null;
        $laborPct = $sales > 0 ? ($laborCost / $sales) : null;
        $rentPct = $sales > 0 ? ($rentCost / $sales) : null;
        $otherExpensesPct = $sales > 0 ? ($otherExpenses / $sales) : null;
        $wastePct = $sales > 0 ? ($wasteCost / $sales) : null;
        $inventoryVariancePct = $sales > 0 ? ($inventoryVarianceCost / $sales) : null;

        $hasNoExpenses = ($sales > 0 && $totalExpenses == 0.0);

        // Comparisons
        $comparisons = [];
        if ($compKpi !== null && $compPeriod !== null) {
            $compSales = round($compKpi->net_sales_after_returns, 2);
            $compCogs = round($compKpi->cogs - $compKpi->returned_cogs_recovered, 2);
            $compGrossProfit = round($compSales - $compCogs, 2);
            $compGrossMargin = $compSales > 0 ? ($compGrossProfit / $compSales) : null;

            if ($this->laborBasis === 'allocated') {
                $compLabor = round(AllocatedCost::labor([$branchId], $compPeriod), 2);
                $compRent = round(AllocatedCost::rent([$branchId], $compPeriod), 2);
            } else {
                $compLabor = round($compKpi->labor_cost, 2);
                $compRent = round($compKpi->rent_cost, 2);
            }

            $compOther = round($compKpi->other_expenses, 2);
            $compTotalExpenses = ($this->laborBasis === 'allocated')
                ? round($compLabor + $compRent + $compOther, 2)
                : round($compKpi->expenses_total, 2);

            $compWaste = round($compKpi->waste_cost, 2);
            $compVariance = $this->excludeInventoryVariance ? 0.0 : round($compKpi->inventory_variance_cost, 2);
            $compNetProfit = round($compGrossProfit - $compTotalExpenses - $compWaste - $compVariance, 2);
            $compNetMargin = $compSales > 0 ? ($compNetProfit / $compSales) : null;

            $comparisons['sales'] = $this->metricComparison($sales, $compSales);
            $comparisons['cogs'] = $this->metricComparison($cogs, $compCogs);
            $comparisons['grossProfit'] = $this->metricComparison($grossProfit, $compGrossProfit);
            $comparisons['grossMargin'] = $this->marginComparison($grossMargin, $compGrossMargin);
            $comparisons['laborCost'] = $this->metricComparison($laborCost, $compLabor);
            $comparisons['rentCost'] = $this->metricComparison($rentCost, $compRent);
            $comparisons['otherExpenses'] = $this->metricComparison($otherExpenses, $compOther);
            $comparisons['totalExpenses'] = $this->metricComparison($totalExpenses, $compTotalExpenses);
            $comparisons['wasteCost'] = $this->metricComparison($wasteCost, $compWaste);
            $comparisons['inventoryVarianceCost'] = $this->metricComparison($inventoryVarianceCost, $compVariance);
            $comparisons['netProfit'] = $this->metricComparison($netProfit, $compNetProfit);
            $comparisons['netMargin'] = $this->marginComparison($netMargin, $compNetMargin);
        }

        return new BranchProfitabilityData(
            branchId: $branchId,
            branchName: $branchName,
            sales: $sales,
            cogs: $cogs,
            grossProfit: $grossProfit,
            grossMargin: $grossMargin,
            laborCost: $laborCost,
            rentCost: $rentCost,
            otherExpenses: $otherExpenses,
            totalExpenses: $totalExpenses,
            wasteCost: $wasteCost,
            inventoryVarianceCost: $inventoryVarianceCost,
            netProfit: $netProfit,
            netMargin: $netMargin,
            cogsPct: $cogsPct,
            laborPct: $laborPct,
            rentPct: $rentPct,
            otherExpensesPct: $otherExpensesPct,
            wastePct: $wastePct,
            inventoryVariancePct: $inventoryVariancePct,
            hasNoExpenses: $hasNoExpenses,
            stocktakingDates: $stocktakingDates,
            comparisons: $comparisons,
        );
    }

    /**
     * @param  array<int, BranchProfitabilityData>  $branchesData
     * @param  list<int>  $branchIds
     */
    private function buildGroupData(
        array $branchesData,
        Period $period,
        ?Period $comparisonPeriod,
        array $branchIds,
    ): BranchProfitabilityData {
        $sales = 0.0;
        $cogs = 0.0;
        $grossProfit = 0.0;
        $laborCost = 0.0;
        $rentCost = 0.0;
        $otherExpenses = 0.0;
        $totalExpenses = 0.0;
        $wasteCost = 0.0;
        $inventoryVarianceCost = 0.0;

        foreach ($branchesData as $b) {
            $sales += $b->sales;
            $cogs += $b->cogs;
            $grossProfit += $b->grossProfit;
            $laborCost += $b->laborCost;
            $rentCost += $b->rentCost;
            $otherExpenses += $b->otherExpenses;
            $totalExpenses += $b->totalExpenses;
            $wasteCost += $b->wasteCost;
            $inventoryVarianceCost += $b->inventoryVarianceCost;
        }

        $sales = round($sales, 2);
        $cogs = round($cogs, 2);
        $grossProfit = round($grossProfit, 2);
        $laborCost = round($laborCost, 2);
        $rentCost = round($rentCost, 2);
        $otherExpenses = round($otherExpenses, 2);
        $totalExpenses = round($totalExpenses, 2);
        $wasteCost = round($wasteCost, 2);
        $inventoryVarianceCost = round($inventoryVarianceCost, 2);

        $netProfit = round($grossProfit - $totalExpenses - $wasteCost - $inventoryVarianceCost, 2);
        $grossMargin = $sales > 0 ? ($grossProfit / $sales) : null;
        $netMargin = $sales > 0 ? ($netProfit / $sales) : null;

        $cogsPct = $sales > 0 ? ($cogs / $sales) : null;
        $laborPct = $sales > 0 ? ($laborCost / $sales) : null;
        $rentPct = $sales > 0 ? ($rentCost / $sales) : null;
        $otherExpensesPct = $sales > 0 ? ($otherExpenses / $sales) : null;
        $wastePct = $sales > 0 ? ($wasteCost / $sales) : null;
        $inventoryVariancePct = $sales > 0 ? ($inventoryVarianceCost / $sales) : null;

        $comparisons = [];
        if ($comparisonPeriod !== null) {
            $compSales = 0.0;
            $compCogs = 0.0;
            $compGrossProfit = 0.0;
            $compLabor = 0.0;
            $compRent = 0.0;
            $compOther = 0.0;
            $compTotalExpenses = 0.0;
            $compWaste = 0.0;
            $compVariance = 0.0;
            $hasAnyComp = false;

            foreach ($branchesData as $b) {
                if (isset($b->comparisons['sales'])) {
                    $hasAnyComp = true;
                    $compSales += (float) ($b->comparisons['sales']->comparison ?? 0.0);
                    $compCogs += (float) ($b->comparisons['cogs']->comparison ?? 0.0);
                    $compGrossProfit += (float) ($b->comparisons['grossProfit']->comparison ?? 0.0);
                    $compLabor += (float) ($b->comparisons['laborCost']->comparison ?? 0.0);
                    $compRent += (float) ($b->comparisons['rentCost']->comparison ?? 0.0);
                    $compOther += (float) ($b->comparisons['otherExpenses']->comparison ?? 0.0);
                    $compTotalExpenses += (float) ($b->comparisons['totalExpenses']->comparison ?? 0.0);
                    $compWaste += (float) ($b->comparisons['wasteCost']->comparison ?? 0.0);
                    $compVariance += (float) ($b->comparisons['inventoryVarianceCost']->comparison ?? 0.0);
                }
            }

            if ($hasAnyComp) {
                $compSales = round($compSales, 2);
                $compCogs = round($compCogs, 2);
                $compGrossProfit = round($compGrossProfit, 2);
                $compLabor = round($compLabor, 2);
                $compRent = round($compRent, 2);
                $compOther = round($compOther, 2);
                $compTotalExpenses = round($compTotalExpenses, 2);
                $compWaste = round($compWaste, 2);
                $compVariance = round($compVariance, 2);
                $compNetProfit = round($compGrossProfit - $compTotalExpenses - $compWaste - $compVariance, 2);
                $compGrossMargin = $compSales > 0 ? ($compGrossProfit / $compSales) : null;
                $compNetMargin = $compSales > 0 ? ($compNetProfit / $compSales) : null;

                $comparisons['sales'] = $this->metricComparison($sales, $compSales);
                $comparisons['cogs'] = $this->metricComparison($cogs, $compCogs);
                $comparisons['grossProfit'] = $this->metricComparison($grossProfit, $compGrossProfit);
                $comparisons['grossMargin'] = $this->marginComparison($grossMargin, $compGrossMargin);
                $comparisons['laborCost'] = $this->metricComparison($laborCost, $compLabor);
                $comparisons['rentCost'] = $this->metricComparison($rentCost, $compRent);
                $comparisons['otherExpenses'] = $this->metricComparison($otherExpenses, $compOther);
                $comparisons['totalExpenses'] = $this->metricComparison($totalExpenses, $compTotalExpenses);
                $comparisons['wasteCost'] = $this->metricComparison($wasteCost, $compWaste);
                $comparisons['inventoryVarianceCost'] = $this->metricComparison($inventoryVarianceCost, $compVariance);
                $comparisons['netProfit'] = $this->metricComparison($netProfit, $compNetProfit);
                $comparisons['netMargin'] = $this->marginComparison($netMargin, $compNetMargin);
            }
        }

        return new BranchProfitabilityData(
            branchId: 0,
            branchName: 'الإجمالي',
            sales: $sales,
            cogs: $cogs,
            grossProfit: $grossProfit,
            grossMargin: $grossMargin,
            laborCost: $laborCost,
            rentCost: $rentCost,
            otherExpenses: $otherExpenses,
            totalExpenses: $totalExpenses,
            wasteCost: $wasteCost,
            inventoryVarianceCost: $inventoryVarianceCost,
            netProfit: $netProfit,
            netMargin: $netMargin,
            cogsPct: $cogsPct,
            laborPct: $laborPct,
            rentPct: $rentPct,
            otherExpensesPct: $otherExpensesPct,
            wastePct: $wastePct,
            inventoryVariancePct: $inventoryVariancePct,
            hasNoExpenses: false,
            stocktakingDates: [],
            comparisons: $comparisons,
        );
    }

    private function metricComparison(float $current, float $comparison): ProfitabilityMetricComparison
    {
        $delta = round($current - $comparison, 2);
        $growth = Kpi::growth($current, $comparison);

        return new ProfitabilityMetricComparison(
            current: $current,
            comparison: $comparison,
            delta: $delta,
            growth: $growth,
        );
    }

    private function marginComparison(?float $current, ?float $comparison): ProfitabilityMetricComparison
    {
        $pointsDelta = null;
        if ($current !== null && $comparison !== null) {
            $pointsDelta = round($current - $comparison, 4);
        }
        $growth = Kpi::growth($current, $comparison);

        return new ProfitabilityMetricComparison(
            current: $current,
            comparison: $comparison,
            delta: $pointsDelta,
            growth: $growth,
            marginPointsDelta: $pointsDelta,
        );
    }

    /**
     * Data formatted for KPI Overview cards.
     *
     * @return array<string, mixed>
     */
    public function kpiSummary(): array
    {
        $reportData = $this->data();
        $target = $reportData->group ?? (! empty($reportData->branches) ? array_values($reportData->branches)[0] : null);

        if (! $target) {
            return [
                'net_profit' => 0.0,
                'net_profit_comparison' => null,
                'net_margin' => null,
                'net_margin_comparison' => null,
                'gross_profit' => 0.0,
                'gross_profit_comparison' => null,
                'gross_margin' => null,
                'gross_margin_comparison' => null,
                'food_cost_pct' => null,
                'food_cost_pct_comparison' => null,
                'labor_pct' => null,
                'labor_pct_comparison' => null,
                'sparkline_profit' => [],
            ];
        }

        $series = $this->query->series($this->query->filters()->granularity, $this->query->filters()->period, perBranch: false);
        $sparkline = array_values(array_map(fn (KpiValues $v): float => round($v->net_profit, 0), $series));

        return [
            'net_profit' => $target->netProfit,
            'net_profit_comparison' => $target->comparisons['netProfit']->comparison ?? null,
            'net_margin' => $target->netMargin,
            'net_margin_comparison' => $target->comparisons['netMargin']->comparison ?? null,
            'gross_profit' => $target->grossProfit,
            'gross_profit_comparison' => $target->comparisons['grossProfit']->comparison ?? null,
            'gross_margin' => $target->grossMargin,
            'gross_margin_comparison' => $target->comparisons['grossMargin']->comparison ?? null,
            'food_cost_pct' => $target->cogsPct,
            'food_cost_pct_comparison' => ($target->comparisons['cogs']->comparison ?? null) !== null && ($target->comparisons['sales']->comparison ?? 0.0) > 0
                ? ($target->comparisons['cogs']->comparison / $target->comparisons['sales']->comparison)
                : null,
            'labor_pct' => $target->laborPct,
            'labor_pct_comparison' => ($target->comparisons['laborCost']->comparison ?? null) !== null && ($target->comparisons['sales']->comparison ?? 0.0) > 0
                ? ($target->comparisons['laborCost']->comparison / $target->comparisons['sales']->comparison)
                : null,
            'sparkline_profit' => $sparkline,
        ];
    }

    /**
     * Margin Ladder: horizontal bars of K15 per branch, sorted descending, with group benchmark.
     *
     * @return array{labels: list<string>, data: list<float>, colors: list<string>, groupMargin: ?float}
     */
    public function marginLadderData(): array
    {
        $reportData = $this->data();
        $branches = array_values($reportData->branches);

        // Sort branches by netMargin descending
        usort($branches, function (BranchProfitabilityData $a, BranchProfitabilityData $b): int {
            $ma = $a->netMargin ?? -999.0;
            $mb = $b->netMargin ?? -999.0;

            return $mb <=> $ma;
        });

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($branches as $branch) {
            $labels[] = $branch->branchName;
            $data[] = $branch->netMargin !== null ? round($branch->netMargin * 100, 1) : 0.0;
            $colors[] = BranchColor::for($branch->branchId);
        }

        $groupMargin = $reportData->group?->netMargin !== null
            ? round($reportData->group->netMargin * 100, 1)
            : null;

        return [
            'labels' => $labels,
            'data' => $data,
            'colors' => $colors,
            'groupMargin' => $groupMargin,
        ];
    }

    /**
     * Margin Leakage: Stacked bars of cost ratios as % of sales for each branch.
     * Segments + net margin = 100% of sales.
     *
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
     */
    public function marginLeakageData(): array
    {
        $reportData = $this->data();
        $items = array_values($reportData->branches);

        $labels = [];
        $foodCostData = [];
        $laborData = [];
        $rentData = [];
        $otherData = [];
        $wasteData = [];
        $varianceData = [];
        $netMarginData = [];

        foreach ($items as $branch) {
            $labels[] = $branch->branchName;
            $sales = $branch->sales;

            if ($sales > 0) {
                $foodCostData[] = round(($branch->cogs / $sales) * 100, 1);
                $laborData[] = round(($branch->laborCost / $sales) * 100, 1);
                $rentData[] = round(($branch->rentCost / $sales) * 100, 1);
                $otherData[] = round(($branch->otherExpenses / $sales) * 100, 1);
                $wasteData[] = round(($branch->wasteCost / $sales) * 100, 1);
                $varianceData[] = round(($branch->inventoryVarianceCost / $sales) * 100, 1);
                $netMarginData[] = round(($branch->netProfit / $sales) * 100, 1);
            } else {
                $foodCostData[] = 0.0;
                $laborData[] = 0.0;
                $rentData[] = 0.0;
                $otherData[] = 0.0;
                $wasteData[] = 0.0;
                $varianceData[] = 0.0;
                $netMarginData[] = 0.0;
            }
        }

        $datasets = [
            [
                'label' => 'تكلفة الخامات (Food Cost)',
                'data' => $foodCostData,
                'backgroundColor' => '#f59e0b',
            ],
            [
                'label' => 'العمالة (Labor)',
                'data' => $laborData,
                'backgroundColor' => '#3b82f6',
            ],
            [
                'label' => 'الإيجار (Rent)',
                'data' => $rentData,
                'backgroundColor' => '#8b5cf6',
            ],
            [
                'label' => 'مصروفات أخرى (Other Expenses)',
                'data' => $otherData,
                'backgroundColor' => '#6b7280',
            ],
            [
                'label' => 'الهالك (Waste)',
                'data' => $wasteData,
                'backgroundColor' => '#ef4444',
            ],
            [
                'label' => 'عجز الجرد (Inventory Variance)',
                'data' => $varianceData,
                'backgroundColor' => '#f97316',
            ],
            [
                'label' => 'صافي الربح (Net Margin)',
                'data' => $netMarginData,
                'backgroundColor' => '#10b981',
            ],
        ];

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Margin Trend: K10 (gross margin) and K15 (net margin) per branch by time bucket.
     *
     * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
     */
    public function marginTrendData(): array
    {
        $granularity = $this->query->filters()->granularity;
        $period = $this->query->filters()->period;
        $series = $this->query->series($granularity, $period, perBranch: true);
        $branchIds = $this->query->branchIds();

        $user = $this->query->user();
        $userAccessibleBranchIds = $user->branches()->pluck('branches.id')->map(fn ($id): int => (int) $id)->all();
        $canSeeOtherBranchNames = $this->query->canSeeOtherBranchNames();

        $branchModels = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->pluck('name', 'id');

        $labels = [];
        $firstBranchSeries = reset($series);
        if ($firstBranchSeries) {
            $labels = array_keys($firstBranchSeries);
        }

        $datasets = [];

        foreach ($branchIds as $bId) {
            $rawName = $branchModels->get($bId) ?? "الفرع #{$bId}";
            if (! $canSeeOtherBranchNames && ! in_array($bId, $userAccessibleBranchIds, true)) {
                $branchName = "فرع #{$bId}";
            } else {
                $branchName = $rawName;
            }

            $color = BranchColor::for($bId);
            $branchSeries = $series[$bId] ?? [];

            $grossMarginData = [];
            $netMarginData = [];

            foreach ($labels as $bucket) {
                $kpi = $branchSeries[$bucket] ?? KpiValues::zero();
                $gm = $kpi->grossMargin();
                $nm = $kpi->netMargin();

                $grossMarginData[] = $gm !== null ? round($gm * 100, 1) : 0.0;
                $netMarginData[] = $nm !== null ? round($nm * 100, 1) : 0.0;
            }

            $datasets[] = [
                'label' => "{$branchName} - مجمل الربح",
                'data' => $grossMarginData,
                'borderColor' => $color,
                'backgroundColor' => $color,
                'fill' => false,
                'tension' => 0.1,
            ];

            $datasets[] = [
                'label' => "{$branchName} - صافي الربح",
                'data' => $netMarginData,
                'borderColor' => $color,
                'backgroundColor' => $color,
                'borderDash' => [5, 5],
                'fill' => false,
                'tension' => 0.1,
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Profit vs Sales Scatter: x = K06 (sales), y = K15 (net margin %).
     *
     * @return array{datasets: list<array<string, mixed>>}
     */
    public function profitSalesScatterData(): array
    {
        $reportData = $this->data();
        $datasets = [];

        foreach ($reportData->branches as $branch) {
            $color = BranchColor::for($branch->branchId);
            $marginPct = $branch->netMargin !== null ? round($branch->netMargin * 100, 1) : 0.0;

            $datasets[] = [
                'label' => $branch->branchName,
                'data' => [
                    [
                        'x' => $branch->sales,
                        'y' => $marginPct,
                    ],
                ],
                'backgroundColor' => $color,
                'borderColor' => $color,
                'pointRadius' => 9,
                'pointHoverRadius' => 12,
            ];
        }

        return [
            'datasets' => $datasets,
        ];
    }
}
