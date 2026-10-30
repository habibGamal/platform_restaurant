<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Reports\ProductPerformanceReport\CategoryPerformanceRow;
use App\Domain\Metrics\Reports\ProductPerformanceReport\CrossBranchMatrix;
use App\Domain\Metrics\Reports\ProductPerformanceReport\ParetoItem;
use App\Domain\Metrics\Reports\ProductPerformanceReport\ParetoReport;
use App\Domain\Metrics\Reports\ProductPerformanceReport\ProductDetailDailyRow;
use App\Domain\Metrics\Reports\ProductPerformanceReport\ProductDetailSummary;
use App\Domain\Metrics\Reports\ProductPerformanceReport\ProductKpiSummary;
use App\Domain\Metrics\Reports\ProductPerformanceReport\ProductPerformanceRow;
use App\Models\Branch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ProductPerformanceReport
{
    public function __construct(
        public readonly MetricsQuery $query,
    ) {}

    public static function for(MetricsQuery $query): self
    {
        return new self($query);
    }

    /**
     * Compute KPI cards summary (Total sold items, Qty sold, Sales, Gross profit, Margin %, Unsold items count).
     */
    public function kpiSummary(): ProductKpiSummary
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return new ProductKpiSummary(
                totalProductsSold: 0,
                totalQtySold: 0.0,
                totalNetSales: 0.0,
                totalGrossProfit: 0.0,
                marginPct: null,
                unsoldProductsCount: 0,
            );
        }

        $period = $this->query->filters()->period;
        $compPeriod = $this->query->filters()->comparison;

        // Current totals from agg_product_daily
        $currentTotals = $this->query->scoped('agg_product_daily', $period)
            ->selectRaw('
                COUNT(DISTINCT COALESCE(product_ref, product_uuid)) as total_products,
                COALESCE(SUM(qty_sold), 0) as total_qty,
                COALESCE(SUM(net_sales), 0) as total_sales,
                COALESCE(SUM(gross_profit), 0) as total_gross_profit
            ')
            ->first();

        $totalProductsSold = (int) ($currentTotals->total_products ?? 0);
        $totalQtySold = (float) ($currentTotals->total_qty ?? 0.0);
        $totalNetSales = (float) ($currentTotals->total_sales ?? 0.0);
        $totalGrossProfit = (float) ($currentTotals->total_gross_profit ?? 0.0);
        $marginPct = $totalNetSales > 0 ? $totalGrossProfit / $totalNetSales : null;

        // Unsold active manufactured items count
        $unsoldProductsCount = $this->countUnsoldManufacturedItems($branchIds, $period);

        // Daily net sales sparkline
        $sparklineRows = $this->query->scoped('agg_product_daily', $period)
            ->selectRaw('business_date, COALESCE(SUM(net_sales), 0) as daily_sales')
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->pluck('daily_sales')
            ->map(fn ($val): float => round((float) $val, 2))
            ->all();

        // Comparison
        $compNetSales = null;
        $compQtySold = null;
        $salesGrowth = null;
        $qtyGrowth = null;

        if ($compPeriod !== null) {
            $compTotals = $this->query->scoped('agg_product_daily', $compPeriod)
                ->selectRaw('
                    COALESCE(SUM(qty_sold), 0) as total_qty,
                    COALESCE(SUM(net_sales), 0) as total_sales
                ')
                ->first();

            if ($compTotals !== null) {
                $compNetSales = (float) $compTotals->total_sales;
                $compQtySold = (float) $compTotals->total_qty;
                $salesGrowth = Kpi::growth($totalNetSales, $compNetSales);
                $qtyGrowth = Kpi::growth($totalQtySold, $compQtySold);
            }
        }

        return new ProductKpiSummary(
            totalProductsSold: $totalProductsSold,
            totalQtySold: $totalQtySold,
            totalNetSales: $totalNetSales,
            totalGrossProfit: $totalGrossProfit,
            marginPct: $marginPct,
            unsoldProductsCount: $unsoldProductsCount,
            comparisonNetSales: $compNetSales,
            comparisonQtySold: $compQtySold,
            salesGrowth: $salesGrowth,
            qtyGrowth: $qtyGrowth,
            salesSparkline: $sparklineRows,
        );
    }

    /**
     * Compute product rankings: Top N, Bottom N, and Unsold list.
     *
     * @return array{
     *     top: list<ProductPerformanceRow>,
     *     bottom: list<ProductPerformanceRow>,
     *     unsold: list<ProductPerformanceRow>,
     *     totalSales: float
     * }
     */
    public function ranking(
        string $rankBy = 'sales',
        int $limit = 50,
        bool $includeLegacy = false,
        ?string $categoryUuid = null,
    ): array {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [
                'top' => [],
                'bottom' => [],
                'unsold' => [],
                'totalSales' => 0.0,
            ];
        }

        $period = $this->query->filters()->period;
        $compPeriod = $this->query->filters()->comparison;

        // 1. Fetch current sales from agg_product_daily
        $currentQuery = $this->query->scoped('agg_product_daily', $period)
            ->selectRaw('
                COALESCE(product_ref, product_uuid) as item_key,
                MAX(product_ref) as product_ref,
                MAX(product_uuid) as product_uuid,
                MAX(category_uuid) as category_uuid,
                SUM(qty_sold) as qty_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                SUM(cogs) as cogs,
                SUM(gross_profit) as gross_profit,
                SUM(returns_qty) as returns_qty,
                SUM(waste_qty) as waste_qty,
                SUM(waste_cost) as waste_cost
            ')
            ->groupBy(DB::raw('COALESCE(product_ref, product_uuid)'));

        if ($categoryUuid !== null && $categoryUuid !== '') {
            $currentQuery->where('category_uuid', $categoryUuid);
        }

        $currentRows = $currentQuery->get()->keyBy('item_key');

        // Total sales across all products in scope
        $totalSales = (float) $currentRows->sum('net_sales');

        // 2. Fetch comparison sales if comparison period is active
        $compRows = collect();
        if ($compPeriod !== null) {
            $compQuery = $this->query->scoped('agg_product_daily', $compPeriod)
                ->selectRaw('
                    COALESCE(product_ref, product_uuid) as item_key,
                    SUM(qty_sold) as qty_sold,
                    SUM(net_sales) as net_sales
                ')
                ->groupBy(DB::raw('COALESCE(product_ref, product_uuid)'));

            if ($categoryUuid !== null && $categoryUuid !== '') {
                $compQuery->where('category_uuid', $categoryUuid);
            }

            $compRows = $compQuery->get()->keyBy('item_key');
        }

        // 3. Metadata resolution (Names, categories, units)
        $meta = $this->resolveProductsMetadata($branchIds, $includeLegacy);
        $groupRanks = $this->query->canSeeGroup()
            ? $this->computeGroupRanks($period, $rankBy)
            : collect();

        // 4. Build ProductPerformanceRow list for sold items
        $soldItems = [];
        foreach ($currentRows as $itemKey => $row) {
            $qtySold = (float) $row->qty_sold;
            $netSales = (float) $row->net_sales;

            // Only consider sold if qty > 0 or net_sales > 0
            if ($qtySold <= 0.0 && $netSales <= 0.0) {
                continue;
            }

            $pRef = $row->product_ref;
            $pUuid = $row->product_uuid;
            $cUuid = $row->category_uuid;

            $metaInfo = $meta->get($itemKey) ?? $meta->get($pUuid);
            $name = $metaInfo['name'] ?? ($pRef ?: $pUuid ?: 'صنف');
            $catName = $metaInfo['category_name'] ?? 'عام';
            $unit = $metaInfo['unit'] ?? 'pcs';

            $cogs = (float) $row->cogs;
            $grossProfit = (float) $row->gross_profit;
            $marginPct = $netSales > 0 ? $grossProfit / $netSales : null;
            $shareOfSales = $totalSales > 0 ? $netSales / $totalSales : 0.0;

            $compRow = $compRows->get($itemKey);
            $compQty = $compRow !== null ? (float) $compRow->qty_sold : null;
            $compSales = $compRow !== null ? (float) $compRow->net_sales : null;
            $salesGrowth = Kpi::growth($netSales, $compSales);
            $qtyGrowth = Kpi::growth($qtySold, $compQty);

            $soldItems[] = new ProductPerformanceRow(
                itemKey: (string) $itemKey,
                productUuid: $pUuid,
                productRef: $pRef,
                name: $name,
                categoryUuid: $cUuid,
                categoryName: $catName,
                unit: $unit,
                qtySold: $qtySold,
                grossSales: (float) $row->gross_sales,
                netSales: $netSales,
                cogs: $cogs,
                grossProfit: $grossProfit,
                marginPct: $marginPct,
                shareOfSales: $shareOfSales,
                comparisonQty: $compQty,
                comparisonSales: $compSales,
                salesGrowth: $salesGrowth,
                qtyGrowth: $qtyGrowth,
                returnsQty: (float) $row->returns_qty,
                wasteQty: (float) $row->waste_qty,
                wasteCost: (float) $row->waste_cost,
                groupRank: $groupRanks->get($itemKey),
                isSold: true,
            );
        }

        // Sort sold items according to rankBy
        $sortedSoldItems = $this->sortRows($soldItems, $rankBy, descending: true);

        // Top N
        $top = array_slice($sortedSoldItems, 0, $limit);

        // Bottom N: excludes never-sold items! Ordered ascending by the rank metric
        $bottomSorted = $this->sortRows($soldItems, $rankBy, descending: false);
        $bottom = array_slice($bottomSorted, 0, $limit);

        // 5. Unsold items ("لم يُبع"): active manufactured catalog items with 0 sales
        /** @var list<string> $soldKeys */
        $soldKeys = array_values(array_map('strval', $currentRows->keys()->all()));
        $unsold = $this->getUnsoldItems($branchIds, $soldKeys, $meta, $includeLegacy, $categoryUuid);

        return [
            'top' => $top,
            'bottom' => $bottom,
            'unsold' => $unsold,
            'totalSales' => $totalSales,
        ];
    }

    /**
     * Compute cross-branch matrix for comparable items across branches.
     */
    public function crossBranchMatrix(string $metric = 'qty', bool $includeLegacy = false): CrossBranchMatrix
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return new CrossBranchMatrix([], [], $metric);
        }

        $canSeeOtherNames = $this->query->canSeeOtherBranchNames();
        $userAccessibleBranchIds = $this->query->branchIds();

        // Branches metadata
        $branchesQuery = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->orderBy('id');

        $branchesData = $branchesQuery->get();

        $branchColumns = [];
        foreach ($branchesData as $b) {
            // Respect permissions: if user cannot see other branch names, only show their own branch
            if (! $canSeeOtherNames && ! in_array($b->id, $userAccessibleBranchIds, true)) {
                continue;
            }

            $branchColumns[] = [
                'id' => $b->id,
                'name' => $b->name,
            ];
        }

        $visibleBranchIds = array_column($branchColumns, 'id');
        if (empty($visibleBranchIds)) {
            return new CrossBranchMatrix([], [], $metric);
        }

        $period = $this->query->filters()->period;

        // Days open per branch
        $daysOpenPerBranch = DB::table('agg_branch_daily')
            ->whereIn('branch_id', $visibleBranchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(DISTINCT business_date) as days_count')
            ->pluck('days_count', 'branch_id')
            ->map(fn ($val): int => (int) $val)
            ->all();

        // Aggregated sales per branch & product_ref (or product_uuid)
        $productBranchRows = DB::table('agg_product_daily')
            ->whereIn('branch_id', $visibleBranchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('
                COALESCE(product_ref, product_uuid) as item_key,
                MAX(product_ref) as product_ref,
                branch_id,
                SUM(qty_sold) as branch_qty,
                SUM(net_sales) as branch_sales
            ')
            ->groupBy('item_key', 'branch_id')
            ->get();

        $meta = $this->resolveProductsMetadata($visibleBranchIds, $includeLegacy);

        // Group by item_key
        $itemsMap = [];
        foreach ($productBranchRows as $row) {
            $itemKey = (string) $row->item_key;
            $bId = (int) $row->branch_id;
            $qty = (float) $row->branch_qty;
            $sales = (float) $row->branch_sales;
            $daysOpen = $daysOpenPerBranch[$bId] ?? 1;
            if ($daysOpen <= 0) {
                $daysOpen = 1;
            }

            $perDay = $metric === 'sales' ? ($sales / $daysOpen) : ($qty / $daysOpen);

            if (! isset($itemsMap[$itemKey])) {
                $metaInfo = $meta->get($itemKey);
                $name = $metaInfo['name'] ?? ($row->product_ref ?: $itemKey);
                $catName = $metaInfo['category_name'] ?? 'عام';

                $itemsMap[$itemKey] = [
                    'itemKey' => $itemKey,
                    'productRef' => $row->product_ref,
                    'name' => $name,
                    'categoryName' => $catName,
                    'branches' => [],
                    'rowTotal' => 0.0,
                    'rowMaxPerDay' => 0.0,
                ];
            }

            $itemsMap[$itemKey]['branches'][$bId] = [
                'qty' => $qty,
                'sales' => $sales,
                'daysOpen' => $daysOpen,
                'perDay' => round($perDay, 2),
            ];

            $val = $metric === 'sales' ? $sales : $qty;
            $itemsMap[$itemKey]['rowTotal'] += $val;
            if ($perDay > $itemsMap[$itemKey]['rowMaxPerDay']) {
                $itemsMap[$itemKey]['rowMaxPerDay'] = $perDay;
            }
        }

        // Fill missing branches with 0
        foreach ($itemsMap as &$item) {
            foreach ($visibleBranchIds as $bId) {
                if (! isset($item['branches'][$bId])) {
                    $daysOpen = $daysOpenPerBranch[$bId] ?? 1;
                    $item['branches'][$bId] = [
                        'qty' => 0.0,
                        'sales' => 0.0,
                        'daysOpen' => $daysOpen > 0 ? $daysOpen : 1,
                        'perDay' => 0.0,
                    ];
                }
            }
        }
        unset($item);

        // Sort descending by row total
        $matrixRows = array_values($itemsMap);
        usort($matrixRows, fn ($a, $b) => $b['rowTotal'] <=> $a['rowTotal']);

        return new CrossBranchMatrix(
            branches: $branchColumns,
            rows: $matrixRows,
            metric: $metric,
        );
    }

    /**
     * Category performance breakdown.
     *
     * @return list<CategoryPerformanceRow>
     */
    public function categoryPerformance(?int $scopedBranchId = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        if ($scopedBranchId !== null && in_array($scopedBranchId, $branchIds, true)) {
            $branchIds = [$scopedBranchId];
        }

        $period = $this->query->filters()->period;
        $compPeriod = $this->query->filters()->comparison;

        // Categories metadata from dim_categories
        $categoriesMeta = DB::table('dim_categories')
            ->whereIn('branch_id', $branchIds)
            ->whereNull('deleted_at')
            ->pluck('name', 'uuid')
            ->all();

        $rows = DB::table('agg_product_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw('
                category_uuid,
                COALESCE(product_ref, product_uuid) as item_key,
                SUM(qty_sold) as qty_sold,
                SUM(net_sales) as net_sales,
                SUM(cogs) as cogs,
                SUM(gross_profit) as gross_profit
            ')
            ->groupBy('category_uuid', DB::raw('COALESCE(product_ref, product_uuid)'))
            ->get();

        $compCategorySales = collect();
        if ($compPeriod !== null) {
            $compCategorySales = DB::table('agg_product_daily')
                ->whereIn('branch_id', $branchIds)
                ->whereBetween('business_date', [$compPeriod->from->toDateString(), $compPeriod->to->toDateString()])
                ->selectRaw('category_uuid, SUM(net_sales) as comp_sales')
                ->groupBy('category_uuid')
                ->pluck('comp_sales', 'category_uuid')
                ->map(fn ($v): float => (float) $v);
        }

        // Group by category name to unify across branches
        /** @var array<string, array{categoryUuid: string, name: string, itemKeys: array<string, bool>, qtySold: float, netSales: float, cogs: float, grossProfit: float, compSales: float}> $categoriesMap */
        $categoriesMap = [];
        $totalSales = 0.0;

        foreach ($rows as $row) {
            $cUuid = (string) $row->category_uuid;
            $catName = $categoriesMeta[$cUuid] ?? 'غير مصنف';
            $netSales = (float) $row->net_sales;
            $totalSales += $netSales;

            if (! isset($categoriesMap[$catName])) {
                $categoriesMap[$catName] = [
                    'categoryUuid' => $cUuid,
                    'name' => $catName,
                    'itemKeys' => [],
                    'qtySold' => 0.0,
                    'netSales' => 0.0,
                    'cogs' => 0.0,
                    'grossProfit' => 0.0,
                    'compSales' => 0.0,
                ];
            }

            $categoriesMap[$catName]['itemKeys'][(string) $row->item_key] = true;
            $categoriesMap[$catName]['qtySold'] += (float) $row->qty_sold;
            $categoriesMap[$catName]['netSales'] += $netSales;
            $categoriesMap[$catName]['cogs'] += (float) $row->cogs;
            $categoriesMap[$catName]['grossProfit'] += (float) $row->gross_profit;
            $categoriesMap[$catName]['compSales'] += (float) ($compCategorySales->get($cUuid) ?? 0.0);
        }

        $result = [];
        foreach ($categoriesMap as $cat) {
            $netSales = $cat['netSales'];
            $grossProfit = $cat['grossProfit'];
            $marginPct = $netSales > 0 ? $grossProfit / $netSales : null;
            $shareOfSales = $totalSales > 0 ? $netSales / $totalSales : 0.0;
            $compSales = $compPeriod !== null ? $cat['compSales'] : null;
            $growth = Kpi::growth($netSales, $compSales);

            $result[] = new CategoryPerformanceRow(
                categoryUuid: $cat['categoryUuid'],
                name: $cat['name'],
                productsCount: count($cat['itemKeys']),
                qtySold: $cat['qtySold'],
                netSales: $netSales,
                cogs: $cat['cogs'],
                grossProfit: $grossProfit,
                marginPct: $marginPct,
                shareOfSales: $shareOfSales,
                comparisonSales: $compSales,
                growth: $growth,
            );
        }

        usort($result, fn (CategoryPerformanceRow $a, CategoryPerformanceRow $b) => $b->netSales <=> $a->netSales);

        return $result;
    }

    /**
     * Pareto Analysis: cumulative share of sales by item.
     * Guarantees that item shares sum to 100.0%.
     */
    public function pareto(): ParetoReport
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return new ParetoReport([], 0.0, 0, 0, 0.0);
        }

        $period = $this->query->filters()->period;

        $rows = $this->query->scoped('agg_product_daily', $period)
            ->selectRaw('
                COALESCE(product_ref, product_uuid) as item_key,
                MAX(product_ref) as product_ref,
                MAX(product_uuid) as product_uuid,
                SUM(net_sales) as net_sales
            ')
            ->groupBy(DB::raw('COALESCE(product_ref, product_uuid)'))
            ->havingRaw('SUM(net_sales) > 0')
            ->orderByRaw('SUM(net_sales) DESC')
            ->get();

        $totalSales = (float) $rows->sum('net_sales');
        if ($totalSales <= 0.0 || $rows->isEmpty()) {
            return new ParetoReport([], 0.0, 0, 0, 0.0);
        }

        $meta = $this->resolveProductsMetadata($branchIds, true);

        $items = [];
        $runningSales = 0.0;
        $itemsCount80Pct = 0;
        $count = $rows->count();
        $accumulatedShare = 0.0;

        foreach ($rows as $index => $row) {
            $rank = $index + 1;
            $sales = (float) $row->net_sales;
            $runningSales += $sales;

            // Determine share percentage. For the final item, absorb any fractional rounding difference so sum = 100.00%
            if ($index === $count - 1) {
                $sharePct = round(100.0 - $accumulatedShare, 2);
            } else {
                $sharePct = round(($sales / $totalSales) * 100, 2);
                $accumulatedShare += $sharePct;
            }

            $cumulativePct = min(100.0, round(($runningSales / $totalSales) * 100, 2));
            if ($index === $count - 1) {
                $cumulativePct = 100.0;
            }

            // Flag items that contribute up to 80%
            $isIn80Pct = false;
            if ($itemsCount80Pct === 0 || $cumulativePct <= 80.0 || ($runningSales - $sales) / $totalSales < 0.80) {
                $isIn80Pct = true;
                $itemsCount80Pct++;
            }

            $itemKey = (string) $row->item_key;
            $metaInfo = $meta->get($itemKey);
            $name = $metaInfo['name'] ?? ($row->product_ref ?: $itemKey);

            $items[] = new ParetoItem(
                rank: $rank,
                itemKey: $itemKey,
                name: $name,
                productRef: $row->product_ref,
                sales: $sales,
                sharePct: $sharePct,
                cumulativeSales: round($runningSales, 2),
                cumulativePct: $cumulativePct,
                isIn80Pct: $isIn80Pct,
            );
        }

        $totalCount = count($items);
        $ratio80Pct = $totalCount > 0 ? round($itemsCount80Pct / $totalCount, 3) : 0.0;

        return new ParetoReport(
            items: $items,
            totalSales: $totalSales,
            itemsCount80Pct: $itemsCount80Pct,
            itemsCountTotal: $totalCount,
            itemsRatio80Pct: $ratio80Pct,
        );
    }

    /**
     * Item detail drawer: daily qty, price history, cost trend, returns, waste.
     */
    public function itemDetail(string $itemKey): ?ProductDetailSummary
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return null;
        }

        $period = $this->query->filters()->period;
        $canSeeOtherNames = $this->query->canSeeOtherBranchNames();

        $rows = DB::table('agg_product_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->where(function ($q) use ($itemKey) {
                $q->where('product_ref', $itemKey)
                    ->orWhere('product_uuid', $itemKey);
            })
            ->orderBy('business_date', 'asc')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $meta = $this->resolveProductsMetadata($branchIds, true);
        $metaInfo = $meta->get($itemKey);
        $name = $metaInfo['name'] ?? $itemKey;
        $categoryName = $metaInfo['category_name'] ?? 'عام';
        $unit = $metaInfo['unit'] ?? 'pcs';
        $productRef = $metaInfo['product_ref'] ?? $rows->first()->product_ref;

        $branchesMap = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->pluck('name', 'id')
            ->all();

        $dailyRows = [];
        $totalQty = 0.0;
        $totalSales = 0.0;
        $totalCogs = 0.0;
        $totalProfit = 0.0;
        $totalReturns = 0.0;
        $totalWasteQty = 0.0;
        $totalWasteCost = 0.0;

        foreach ($rows as $row) {
            $bId = (int) $row->branch_id;
            $bName = $canSeeOtherNames ? ($branchesMap[$bId] ?? "فرع {$bId}") : 'فرعي';
            $qty = (float) $row->qty_sold;
            $sales = (float) $row->net_sales;
            $cogs = (float) $row->cogs;
            $profit = (float) $row->gross_profit;
            $retQty = (float) $row->returns_qty;
            $wQty = (float) $row->waste_qty;
            $wCost = (float) $row->waste_cost;

            $totalQty += $qty;
            $totalSales += $sales;
            $totalCogs += $cogs;
            $totalProfit += $profit;
            $totalReturns += $retQty;
            $totalWasteQty += $wQty;
            $totalWasteCost += $wCost;

            $unitCost = $qty > 0 ? $cogs / $qty : 0.0;
            $price = $qty > 0 ? $sales / $qty : 0.0;
            $marginPct = $sales > 0 ? $profit / $sales : null;

            $dailyRows[] = new ProductDetailDailyRow(
                businessDate: (string) $row->business_date,
                branchId: $bId,
                branchName: $bName,
                qtySold: $qty,
                netSales: $sales,
                cogs: $cogs,
                grossProfit: $profit,
                marginPct: $marginPct,
                price: round($price, 2),
                unitCost: round($unitCost, 2),
                returnsQty: $retQty,
                wasteQty: $wQty,
                wasteCost: $wCost,
            );
        }

        $overallMargin = $totalSales > 0 ? $totalProfit / $totalSales : null;

        return new ProductDetailSummary(
            itemKey: $itemKey,
            name: $name,
            productRef: $productRef,
            categoryName: $categoryName,
            unit: $unit,
            totalQtySold: $totalQty,
            totalNetSales: $totalSales,
            totalCogs: $totalCogs,
            totalGrossProfit: $totalProfit,
            marginPct: $overallMargin,
            totalReturnsQty: $totalReturns,
            totalWasteQty: $totalWasteQty,
            totalWasteCost: $totalWasteCost,
            dailyRows: $dailyRows,
        );
    }

    /**
     * Compute group ranks across the entire organization without exposing other branch numbers.
     *
     * @return Collection<string, int>
     */
    private function computeGroupRanks(Period $period, string $rankBy): Collection
    {
        if (! $this->query->canSeeGroup()) {
            return collect();
        }

        $orgId = $this->query->user->organization_id;
        if (! $orgId) {
            return collect();
        }

        $sortColumn = match ($rankBy) {
            'qty' => 'SUM(qty_sold)',
            'gross_profit' => 'SUM(gross_profit)',
            'margin_pct' => 'CASE WHEN SUM(net_sales) > 0 THEN SUM(gross_profit) / SUM(net_sales) ELSE 0 END',
            default => 'SUM(net_sales)',
        };

        $rows = DB::table('agg_product_daily')
            ->where('organization_id', $orgId)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->selectRaw("
                COALESCE(product_ref, product_uuid) as item_key,
                {$sortColumn} as sort_metric
            ")
            ->groupBy(DB::raw('COALESCE(product_ref, product_uuid)'))
            ->havingRaw('SUM(qty_sold) > 0 OR SUM(net_sales) > 0')
            ->orderByRaw("{$sortColumn} DESC")
            ->get();

        $ranks = [];
        foreach ($rows as $idx => $r) {
            $ranks[(string) $r->item_key] = $idx + 1;
        }

        return collect($ranks);
    }

    /**
     * Count active manufactured items in dim_products with 0 sales in the period.
     *
     * @param  list<int>  $branchIds
     */
    private function countUnsoldManufacturedItems(array $branchIds, Period $period): int
    {
        $soldKeys = DB::table('agg_product_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->where('qty_sold', '>', 0)
            ->selectRaw('DISTINCT COALESCE(product_ref, product_uuid) as item_key')
            ->pluck('item_key')
            ->all();

        return (int) DB::table('dim_products')
            ->whereIn('branch_id', $branchIds)
            ->where('type', 'manufactured')
            ->where('legacy', false)
            ->whereNull('deleted_at')
            ->whereNotIn('uuid', $soldKeys)
            ->where(function ($q) use ($soldKeys) {
                $q->whereNull('product_ref')
                    ->orWhereNotIn('product_ref', $soldKeys);
            })
            ->selectRaw('COUNT(DISTINCT COALESCE(product_ref, uuid)) as unsold_count')
            ->value('unsold_count');
    }

    /**
     * Get list of unsold items ("لم يُبع").
     *
     * @param  list<int>  $branchIds
     * @param  list<string>  $soldItemKeys
     * @param  Collection<string, array{name: string, category_name: string, unit: string, product_ref: ?string}>  $meta
     * @return list<ProductPerformanceRow>
     */
    private function getUnsoldItems(
        array $branchIds,
        array $soldItemKeys,
        Collection $meta,
        bool $includeLegacy,
        ?string $categoryUuid,
    ): array {
        $query = DB::table('dim_products')
            ->whereIn('branch_id', $branchIds)
            ->where('type', 'manufactured')
            ->whereNull('deleted_at');

        if (! $includeLegacy) {
            $query->where('legacy', false);
        }

        if ($categoryUuid !== null && $categoryUuid !== '') {
            $query->where('category_uuid', $categoryUuid);
        }

        $products = $query->get();

        $unsoldMap = [];
        foreach ($products as $p) {
            $itemKey = (string) ($p->product_ref ?: $p->uuid);
            if (in_array($itemKey, $soldItemKeys, true) || in_array((string) $p->uuid, $soldItemKeys, true)) {
                continue;
            }

            if (isset($unsoldMap[$itemKey])) {
                continue;
            }

            $metaInfo = $meta->get($itemKey) ?? $meta->get($p->uuid);
            $name = $metaInfo['name'] ?? $p->name;
            $catName = $metaInfo['category_name'] ?? 'عام';
            $unit = $p->unit ?? 'pcs';

            $unsoldMap[$itemKey] = new ProductPerformanceRow(
                itemKey: $itemKey,
                productUuid: $p->uuid,
                productRef: $p->product_ref,
                name: $name,
                categoryUuid: $p->category_uuid,
                categoryName: $catName,
                unit: $unit,
                qtySold: 0.0,
                grossSales: 0.0,
                netSales: 0.0,
                cogs: 0.0,
                grossProfit: 0.0,
                marginPct: null,
                shareOfSales: 0.0,
                isSold: false,
            );
        }

        $unsoldList = array_values($unsoldMap);
        usort($unsoldList, fn ($a, $b) => strcmp($a->name, $b->name));

        return $unsoldList;
    }

    /**
     * Resolve product metadata: canonical names from catalog_items, latest names from dim_products, categories.
     *
     * @param  list<int>  $branchIds
     * @return Collection<string, array{name: string, category_name: string, unit: string, product_ref: ?string}>
     */
    private function resolveProductsMetadata(array $branchIds, bool $includeLegacy): Collection
    {
        $orgId = $this->query->user->organization_id;

        // Categories map
        $categoriesMap = DB::table('dim_categories')
            ->whereIn('branch_id', $branchIds)
            ->whereNull('deleted_at')
            ->pluck('name', 'uuid')
            ->all();

        // Catalog items (cross-branch identity canonical names)
        $catalogMap = [];
        if ($orgId) {
            $catalogMap = DB::table('catalog_items')
                ->where('organization_id', $orgId)
                ->pluck('canonical_name', 'product_ref')
                ->all();
        }

        // Dim products
        $dimQuery = DB::table('dim_products')
            ->whereIn('branch_id', $branchIds)
            ->whereNull('deleted_at')
            ->orderBy('id', 'asc');

        if (! $includeLegacy) {
            $dimQuery->where('legacy', false);
        }

        $dimProducts = $dimQuery->get();

        /** @var array<string, array{name: string, category_name: string, unit: string, product_ref: ?string}> $meta */
        $meta = [];
        foreach ($dimProducts as $dp) {
            $pRef = is_string($dp->product_ref) && $dp->product_ref !== '' ? $dp->product_ref : null;
            $canonicalName = $pRef !== null ? ($catalogMap[$pRef] ?? null) : null;
            $name = is_string($canonicalName) && $canonicalName !== '' ? $canonicalName : (string) $dp->name;
            $catUuid = is_string($dp->category_uuid) ? $dp->category_uuid : null;
            $catName = $catUuid !== null && isset($categoriesMap[$catUuid]) ? (string) $categoriesMap[$catUuid] : 'عام';
            $unit = is_string($dp->unit) && $dp->unit !== '' ? $dp->unit : 'pcs';

            $data = [
                'name' => $name,
                'category_name' => $catName,
                'unit' => $unit,
                'product_ref' => $pRef,
            ];

            // Index by product_uuid
            $meta[(string) $dp->uuid] = $data;

            // Index by product_ref if present
            if ($pRef !== null) {
                $meta[$pRef] = $data;
            }
        }

        return collect($meta);
    }

    /**
     * Sort ProductPerformanceRow array according to rankBy.
     *
     * @param  list<ProductPerformanceRow>  $rows
     * @return list<ProductPerformanceRow>
     */
    private function sortRows(array $rows, string $rankBy, bool $descending = true): array
    {
        usort($rows, function (ProductPerformanceRow $a, ProductPerformanceRow $b) use ($rankBy, $descending) {
            $valA = match ($rankBy) {
                'qty' => $a->qtySold,
                'gross_profit' => $a->grossProfit,
                'margin_pct' => $a->marginPct ?? -999999.0,
                default => $a->netSales,
            };

            $valB = match ($rankBy) {
                'qty' => $b->qtySold,
                'gross_profit' => $b->grossProfit,
                'margin_pct' => $b->marginPct ?? -999999.0,
                default => $b->netSales,
            };

            if (abs($valA - $valB) < 0.00001) {
                // Secondary sort: netSales DESC
                return $descending ? ($b->netSales <=> $a->netSales) : ($a->netSales <=> $b->netSales);
            }

            return $descending ? ($valB <=> $valA) : ($valA <=> $valB);
        });

        return $rows;
    }
}
