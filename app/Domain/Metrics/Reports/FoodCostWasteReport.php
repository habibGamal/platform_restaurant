<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Reports\FoodCostWasteReport\BranchFoodCostDto;
use App\Domain\Metrics\Reports\FoodCostWasteReport\FoodCostKpiOverviewDto;
use App\Domain\Metrics\Reports\FoodCostWasteReport\PurchasePriceMovementDto;
use App\Domain\Metrics\Reports\FoodCostWasteReport\StocktakingVarianceDto;
use App\Domain\Metrics\Reports\FoodCostWasteReport\TopWastedItemDto;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Branch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FoodCostWasteReport
{
    public const float FOOD_COST_BAND_MIN = 0.28;

    public const float FOOD_COST_BAND_MAX = 0.35;

    public function __construct(
        public readonly MetricsQuery $query,
    ) {}

    public static function for(MetricsQuery $query): self
    {
        return new self($query);
    }

    /**
     * Headline KPI overview (totals across selected branches, with comparisons).
     */
    public function kpiOverview(): FoodCostKpiOverviewDto
    {
        $current = $this->query->totals();
        $comparison = $this->query->comparisonTotals();

        $foodCostPct = $current->foodCostPct();
        $foodCostPctComparison = $comparison?->foodCostPct();

        $wasteCost = $current->waste_cost;
        $wasteCostComparison = $comparison?->waste_cost;

        $wastePct = $current->wastePct();
        $wastePctComparison = $comparison?->wastePct();

        $inventoryVarianceCost = $current->inventory_variance_cost;
        $inventoryVarianceCostComparison = $comparison?->inventory_variance_cost;

        $currentSales = $current->net_sales_after_returns;
        $inventoryVariancePct = $currentSales > 0 ? $inventoryVarianceCost / $currentSales : null;

        $compSales = $comparison?->net_sales_after_returns;
        $inventoryVariancePctComparison = ($compSales !== null && $compSales > 0)
            ? $comparison->inventory_variance_cost / $compSales
            : null;

        $totalLossCost = $wasteCost + $inventoryVarianceCost;
        $totalLossCostComparison = $comparison !== null
            ? ($comparison->waste_cost + $comparison->inventory_variance_cost)
            : null;

        $totalLossPct = $currentSales > 0 ? $totalLossCost / $currentSales : null;
        $totalLossPctComparison = ($compSales !== null && $compSales > 0 && $totalLossCostComparison !== 0.0)
            ? $totalLossCostComparison / $compSales
            : null;

        $dailySeries = $this->query->series(Granularity::day);
        $sparklineFoodCost = [];
        foreach ($dailySeries as $kpis) {
            $pct = $kpis->foodCostPct();
            $sparklineFoodCost[] = $pct !== null ? round($pct * 100, 1) : 0.0;
        }

        return new FoodCostKpiOverviewDto(
            foodCostPct: $foodCostPct,
            foodCostPctComparison: $foodCostPctComparison,
            wasteCost: $wasteCost,
            wasteCostComparison: $wasteCostComparison,
            wastePct: $wastePct,
            wastePctComparison: $wastePctComparison,
            inventoryVarianceCost: $inventoryVarianceCost,
            inventoryVarianceCostComparison: $inventoryVarianceCostComparison,
            inventoryVariancePct: $inventoryVariancePct,
            inventoryVariancePctComparison: $inventoryVariancePctComparison,
            totalLossCost: $totalLossCost,
            totalLossCostComparison: $totalLossCostComparison,
            totalLossPct: $totalLossPct,
            totalLossPctComparison: $totalLossPctComparison,
            sparklineFoodCost: $sparklineFoodCost,
        );
    }

    /**
     * Matrix of food cost and waste KPIs per branch.
     *
     * @return array<int, BranchFoodCostDto> Keyed by branch_id
     */
    public function branchMatrix(): array
    {
        $byBranch = $this->query->byBranch();
        $result = [];

        foreach ($byBranch as $branchId => $kpis) {
            $branchName = $this->resolveBranchName($branchId);
            $cogs = $kpis->cogs - $kpis->returned_cogs_recovered;
            $sales = $kpis->net_sales_after_returns;
            $foodCostPct = $kpis->foodCostPct();
            $wasteCost = $kpis->waste_cost;
            $wastePct = $kpis->wastePct();
            $wasteShareOfUsage = $kpis->wasteShareOfUsage();
            $inventoryVarianceCost = $kpis->inventory_variance_cost;
            $inventoryVariancePct = $sales > 0 ? $inventoryVarianceCost / $sales : null;
            $totalLossCost = $wasteCost + $inventoryVarianceCost;
            $totalLossPct = $sales > 0 ? $totalLossCost / $sales : null;
            $wasteRecorded = ($wasteCost > 0);

            [$targetBandStatus, $targetBandColor] = $this->evaluateTargetBand($foodCostPct);

            $result[$branchId] = new BranchFoodCostDto(
                branchId: $branchId,
                branchName: $branchName,
                netSalesAfterReturns: $sales,
                cogs: $cogs,
                foodCostPct: $foodCostPct,
                wasteCost: $wasteCost,
                wastePct: $wastePct,
                wasteShareOfUsage: $wasteShareOfUsage,
                inventoryVarianceCost: $inventoryVarianceCost,
                inventoryVariancePct: $inventoryVariancePct,
                totalLossCost: $totalLossCost,
                totalLossPct: $totalLossPct,
                wasteRecorded: $wasteRecorded,
                targetBandStatus: $targetBandStatus,
                targetBandColor: $targetBandColor,
            );
        }

        return $result;
    }

    /**
     * Benchmark group summary (returns null if user cannot view group analytics).
     */
    public function groupBenchmark(): ?BranchFoodCostDto
    {
        if (! $this->query->canSeeGroup()) {
            return null;
        }

        $totals = $this->query->totals();
        $cogs = $totals->cogs - $totals->returned_cogs_recovered;
        $sales = $totals->net_sales_after_returns;
        $foodCostPct = $totals->foodCostPct();
        $wasteCost = $totals->waste_cost;
        $wastePct = $totals->wastePct();
        $wasteShareOfUsage = $totals->wasteShareOfUsage();
        $inventoryVarianceCost = $totals->inventory_variance_cost;
        $inventoryVariancePct = $sales > 0 ? $inventoryVarianceCost / $sales : null;
        $totalLossCost = $wasteCost + $inventoryVarianceCost;
        $totalLossPct = $sales > 0 ? $totalLossCost / $sales : null;
        $wasteRecorded = ($wasteCost > 0);

        [$targetBandStatus, $targetBandColor] = $this->evaluateTargetBand($foodCostPct);

        return new BranchFoodCostDto(
            branchId: 0,
            branchName: 'متوسط المجموعة',
            netSalesAfterReturns: $sales,
            cogs: $cogs,
            foodCostPct: $foodCostPct,
            wasteCost: $wasteCost,
            wastePct: $wastePct,
            wasteShareOfUsage: $wasteShareOfUsage,
            inventoryVarianceCost: $inventoryVarianceCost,
            inventoryVariancePct: $inventoryVariancePct,
            totalLossCost: $totalLossCost,
            totalLossPct: $totalLossPct,
            wasteRecorded: $wasteRecorded,
            targetBandStatus: $targetBandStatus,
            targetBandColor: $targetBandColor,
        );
    }

    /**
     * Weekly (or requested granularity) food cost % trend per branch.
     *
     * @return array{labels: list<string>, datasets: list<array{branchId: int, branchName: string, data: list<?float>}>}
     */
    public function foodCostTrend(?Granularity $g = null): array
    {
        $g ??= Granularity::week;
        /** @var array<int, array<string, KpiValues>> $series */
        $series = $this->query->series($g, null, perBranch: true);

        $labels = [];
        $firstBranch = reset($series);
        if ($firstBranch !== false) {
            $labels = array_keys($firstBranch);
        }

        $datasets = [];
        foreach ($series as $branchId => $buckets) {
            $data = [];
            foreach ($buckets as $kpis) {
                $pct = $kpis->foodCostPct();
                $data[] = $pct !== null ? round($pct * 100, 1) : null;
            }

            $datasets[] = [
                'branchId' => $branchId,
                'branchName' => $this->resolveBranchName($branchId),
                'data' => $data,
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /**
     * Top wasted items ordered by total waste cost.
     *
     * @return list<TopWastedItemDto>
     */
    public function topWastedItems(int $limit = 20, ?int $branchIdFilter = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        $builder = $this->query->scoped('agg_product_daily')
            ->where('waste_cost', '>', 0);

        if ($branchIdFilter !== null && in_array($branchIdFilter, $branchIds, true)) {
            $builder->where('branch_id', $branchIdFilter);
        }

        $rows = $builder
            ->groupBy('branch_id', 'product_uuid')
            ->select([
                'branch_id',
                'product_uuid',
                DB::raw('SUM(waste_qty) as total_waste_qty'),
                DB::raw('SUM(waste_cost) as total_waste_cost'),
            ])
            ->orderByDesc('total_waste_cost')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $productUuids = $rows->pluck('product_uuid')->unique()->all();
        $dims = DB::table('dim_products')
            ->whereIn('dim_products.branch_id', $branchIds)
            ->whereIn('dim_products.uuid', $productUuids)
            ->leftJoin('catalog_items', function ($join): void {
                $join->on('dim_products.organization_id', '=', 'catalog_items.organization_id')
                    ->on('dim_products.product_ref', '=', 'catalog_items.product_ref');
            })
            ->select([
                'dim_products.branch_id',
                'dim_products.uuid',
                'dim_products.name',
                'dim_products.product_ref',
                'dim_products.unit',
                'catalog_items.canonical_name',
            ])
            ->get()
            ->keyBy(fn ($d): string => $d->branch_id.'|'.$d->uuid);

        $branchTotals = $this->query->byBranch();

        $items = [];
        foreach ($rows as $row) {
            $branchId = (int) $row->branch_id;
            $productUuid = (string) $row->product_uuid;
            $branchWaste = $branchTotals[$branchId]->waste_cost ?? 0.0;
            $cost = (float) $row->total_waste_cost;
            $share = $branchWaste > 0 ? $cost / $branchWaste : null;

            $dim = $dims->get($branchId.'|'.$productUuid);
            $productName = $dim !== null
                ? ($dim->canonical_name ?? $dim->name ?? $dim->product_ref ?? 'منتج غير معروف')
                : 'منتج غير معروف';

            $items[] = new TopWastedItemDto(
                branchId: $branchId,
                branchName: $this->resolveBranchName($branchId),
                productUuid: $productUuid,
                productName: (string) $productName,
                productRef: ($dim !== null && $dim->product_ref !== null) ? (string) $dim->product_ref : null,
                unit: ($dim !== null && $dim->unit !== null) ? (string) $dim->unit : null,
                wasteQty: (float) $row->total_waste_qty,
                wasteCost: $cost,
                wasteShareOfBranch: $share,
            );
        }

        return $items;
    }

    /**
     * Stocktaking variances for the latest stocktaking per branch.
     *
     * @return list<StocktakingVarianceDto>
     */
    public function stocktakingVariances(int $limit = 20, ?int $branchIdFilter = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        if ($branchIdFilter !== null) {
            if (! in_array($branchIdFilter, $branchIds, true)) {
                return [];
            }
            $targetBranchIds = [$branchIdFilter];
        } else {
            $targetBranchIds = $branchIds;
        }

        $period = $this->query->filters->period;

        $stocktakings = DB::table('wh_stocktakings')
            ->whereIn('branch_id', $targetBranchIds)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNull('deleted_at')
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get();

        if ($stocktakings->isEmpty()) {
            return [];
        }

        $latestPerBranch = $stocktakings->groupBy('branch_id')->map(fn ($list) => $list->first());
        $latestUuids = $latestPerBranch->pluck('uuid')->all();

        $itemsQuery = DB::table('wh_stocktaking_items')
            ->whereIn('wh_stocktaking_items.branch_id', $targetBranchIds)
            ->whereIn('wh_stocktaking_items.stocktaking_uuid', $latestUuids)
            ->join('wh_stocktakings', function ($join): void {
                $join->on('wh_stocktaking_items.branch_id', '=', 'wh_stocktakings.branch_id')
                    ->on('wh_stocktaking_items.stocktaking_uuid', '=', 'wh_stocktakings.uuid')
                    ->whereNull('wh_stocktakings.deleted_at');
            })
            ->leftJoin('dim_products', function ($join): void {
                $join->on('wh_stocktaking_items.branch_id', '=', 'dim_products.branch_id')
                    ->on('wh_stocktaking_items.product_uuid', '=', 'dim_products.uuid');
            })
            ->leftJoin('catalog_items', function ($join): void {
                $join->on('dim_products.organization_id', '=', 'catalog_items.organization_id')
                    ->on('dim_products.product_ref', '=', 'catalog_items.product_ref');
            })
            ->select([
                'wh_stocktaking_items.branch_id',
                'wh_stocktaking_items.stocktaking_uuid',
                'wh_stocktakings.business_date',
                'wh_stocktaking_items.product_uuid',
                DB::raw('COALESCE(catalog_items.canonical_name, dim_products.name, "صنف غير معروف") as product_name'),
                'dim_products.product_ref',
                'dim_products.unit',
                'wh_stocktaking_items.stock_quantity',
                'wh_stocktaking_items.real_quantity',
                'wh_stocktaking_items.price',
                DB::raw('(wh_stocktaking_items.stock_quantity - wh_stocktaking_items.real_quantity) as shortage_quantity'),
                DB::raw('((wh_stocktaking_items.stock_quantity - wh_stocktaking_items.real_quantity) * wh_stocktaking_items.price) as shortage_value'),
            ])
            ->orderByDesc('shortage_value')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($itemsQuery as $row) {
            $branchId = (int) $row->branch_id;
            $results[] = new StocktakingVarianceDto(
                branchId: $branchId,
                branchName: $this->resolveBranchName($branchId),
                stocktakingUuid: (string) $row->stocktaking_uuid,
                businessDate: (string) $row->business_date,
                productUuid: (string) $row->product_uuid,
                productName: (string) $row->product_name,
                productRef: $row->product_ref !== null ? (string) $row->product_ref : null,
                unit: $row->unit !== null ? (string) $row->unit : null,
                stockQuantity: (float) $row->stock_quantity,
                realQuantity: (float) $row->real_quantity,
                shortageQuantity: (float) $row->shortage_quantity,
                price: (float) $row->price,
                shortageValue: (float) $row->shortage_value,
            );
        }

        return $results;
    }

    /**
     * Purchase price movement comparing average purchase prices between current and comparison periods.
     * When comparison period is set, only products present (by product_ref) in BOTH periods are included.
     *
     * @return list<PurchasePriceMovementDto>
     */
    public function purchasePriceMovement(int $limit = 20, ?int $branchIdFilter = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        if ($branchIdFilter !== null) {
            if (! in_array($branchIdFilter, $branchIds, true)) {
                return [];
            }
            $targetBranchIds = [$branchIdFilter];
        } else {
            $targetBranchIds = $branchIds;
        }

        $currentPeriod = $this->query->filters->period;
        $comparisonPeriod = $this->query->filters->comparison;

        // Current purchases
        $currentPurchases = DB::table('wh_purchases')
            ->whereIn('wh_purchases.branch_id', $targetBranchIds)
            ->where('wh_purchases.kind', 'invoice')
            ->whereNull('wh_purchases.deleted_at')
            ->whereBetween('wh_purchases.business_date', [
                $currentPeriod->from->toDateString(),
                $currentPeriod->to->toDateString(),
            ])
            ->join('wh_purchase_items', function ($join): void {
                $join->on('wh_purchases.branch_id', '=', 'wh_purchase_items.branch_id')
                    ->on('wh_purchases.uuid', '=', 'wh_purchase_items.purchase_uuid');
            })
            ->leftJoin('dim_products', function ($join): void {
                $join->on('wh_purchase_items.branch_id', '=', 'dim_products.branch_id')
                    ->on('wh_purchase_items.product_uuid', '=', 'dim_products.uuid');
            })
            ->leftJoin('catalog_items', function ($join): void {
                $join->on('dim_products.organization_id', '=', 'catalog_items.organization_id')
                    ->on('dim_products.product_ref', '=', 'catalog_items.product_ref');
            })
            ->groupBy(
                'wh_purchases.branch_id',
                'dim_products.product_ref',
                'catalog_items.canonical_name',
                'dim_products.name',
                'dim_products.unit',
                'catalog_items.unit_factor',
                'catalog_items.id',
            )
            ->select([
                'wh_purchases.branch_id',
                DB::raw('COALESCE(dim_products.product_ref, "UNMAPPED") as product_ref'),
                DB::raw('COALESCE(catalog_items.canonical_name, dim_products.name, dim_products.product_ref, "صنف غير معروف") as product_name'),
                'dim_products.unit',
                DB::raw('COALESCE(catalog_items.unit_factor, 1.0) as unit_factor'),
                DB::raw('CASE WHEN catalog_items.id IS NOT NULL THEN 1 ELSE 0 END as is_mapped'),
                DB::raw('SUM(wh_purchase_items.quantity) as total_qty'),
                DB::raw('SUM(wh_purchase_items.total) as total_spend'),
            ])
            ->get();

        if ($currentPurchases->isEmpty()) {
            return [];
        }

        /** @var Collection<string, object> $compPurchases */
        $compPurchases = collect();
        if ($comparisonPeriod !== null) {
            $compRows = DB::table('wh_purchases')
                ->whereIn('wh_purchases.branch_id', $targetBranchIds)
                ->where('wh_purchases.kind', 'invoice')
                ->whereNull('wh_purchases.deleted_at')
                ->whereBetween('wh_purchases.business_date', [
                    $comparisonPeriod->from->toDateString(),
                    $comparisonPeriod->to->toDateString(),
                ])
                ->join('wh_purchase_items', function ($join): void {
                    $join->on('wh_purchases.branch_id', '=', 'wh_purchase_items.branch_id')
                        ->on('wh_purchases.uuid', '=', 'wh_purchase_items.purchase_uuid');
                })
                ->leftJoin('dim_products', function ($join): void {
                    $join->on('wh_purchase_items.branch_id', '=', 'dim_products.branch_id')
                        ->on('wh_purchase_items.product_uuid', '=', 'dim_products.uuid');
                })
                ->groupBy(
                    'wh_purchases.branch_id',
                    'dim_products.product_ref',
                )
                ->select([
                    'wh_purchases.branch_id',
                    DB::raw('COALESCE(dim_products.product_ref, "UNMAPPED") as product_ref'),
                    DB::raw('SUM(wh_purchase_items.quantity) as comp_qty'),
                    DB::raw('SUM(wh_purchase_items.total) as comp_spend'),
                ])
                ->get();

            $compPurchases = $compRows->keyBy(fn ($r): string => $r->branch_id.'|'.$r->product_ref);

            $currentRefs = $currentPurchases->pluck('product_ref')->unique()->all();
            $compRefs = $compRows->pluck('product_ref')->unique()->all();
            $commonRefs = array_values(array_intersect($currentRefs, $compRefs));

            // Acceptance criterion: Price movement shows only products present (same product_ref) in both periods
            $currentPurchases = $currentPurchases->filter(fn ($row): bool => in_array($row->product_ref, $commonRefs, true));
        }

        $results = [];
        foreach ($currentPurchases as $row) {
            $branchId = (int) $row->branch_id;
            $productRef = (string) $row->product_ref;
            $currentQty = (float) $row->total_qty;
            $currentSpend = (float) $row->total_spend;
            $currentAvgPrice = $currentQty > 0 ? $currentSpend / $currentQty : null;

            $comparisonQty = null;
            $comparisonSpend = null;
            $comparisonAvgPrice = null;
            $priceChangePct = null;

            if ($comparisonPeriod !== null) {
                $compKey = $branchId.'|'.$productRef;
                $comp = $compPurchases->get($compKey);
                if ($comp !== null) {
                    $comparisonQty = (float) $comp->comp_qty;
                    $comparisonSpend = (float) $comp->comp_spend;
                    $comparisonAvgPrice = $comparisonQty > 0 ? $comparisonSpend / $comparisonQty : null;
                    if ($currentAvgPrice !== null && $comparisonAvgPrice !== null && $comparisonAvgPrice > 0) {
                        $priceChangePct = ($currentAvgPrice - $comparisonAvgPrice) / $comparisonAvgPrice;
                    }
                }
            }

            $results[] = new PurchasePriceMovementDto(
                branchId: $branchId,
                branchName: $this->resolveBranchName($branchId),
                productRef: $productRef,
                productName: (string) $row->product_name,
                unit: $row->unit !== null ? (string) $row->unit : null,
                isMapped: (bool) $row->is_mapped,
                currentQty: $currentQty,
                currentSpend: $currentSpend,
                currentAvgPrice: $currentAvgPrice,
                comparisonQty: $comparisonQty,
                comparisonSpend: $comparisonSpend,
                comparisonAvgPrice: $comparisonAvgPrice,
                priceChangePct: $priceChangePct,
            );
        }

        usort($results, fn (PurchasePriceMovementDto $a, PurchasePriceMovementDto $b): int => $b->currentSpend <=> $a->currentSpend);

        return array_slice($results, 0, $limit);
    }

    /**
     * @return array{0: string, 1: string} [targetBandStatus, targetBandColor]
     */
    public function evaluateTargetBand(?float $foodCostPct): array
    {
        if ($foodCostPct === null) {
            return ['none', 'gray'];
        }

        if ($foodCostPct < self::FOOD_COST_BAND_MIN) {
            return ['below_band', 'success'];
        }

        if ($foodCostPct <= self::FOOD_COST_BAND_MAX) {
            return ['in_band', 'success'];
        }

        return ['above_band', 'danger'];
    }

    public function resolveBranchName(int $branchId): string
    {
        if ($branchId === 0) {
            return 'متوسط المجموعة';
        }

        if (! $this->query->canSeeOtherBranchNames()) {
            $userBranchIds = AccessibleBranches::ids($this->query->user);
            if (! in_array($branchId, $userBranchIds, true)) {
                return "فرع #{$branchId}";
            }
        }

        $branch = Branch::withoutGlobalScopes()->find($branchId);

        if ($branch !== null) {
            return $branch->name;
        }

        return "فرع #{$branchId}";
    }
}
