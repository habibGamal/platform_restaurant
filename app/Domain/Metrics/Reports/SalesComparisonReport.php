<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Reports\SalesComparisonReport\ChannelMixData;
use App\Domain\Metrics\Reports\SalesComparisonReport\DiscountIntensityRow;
use App\Domain\Metrics\Reports\SalesComparisonReport\GrowthDecompositionRow;
use App\Domain\Metrics\Reports\SalesComparisonReport\SalesComparisonData;
use App\Domain\Metrics\Reports\SalesComparisonReport\SalesKpiMatrixRow;
use App\Domain\Metrics\Reports\SalesComparisonReport\SalesTrendData;
use App\Domain\Metrics\Reports\SalesComparisonReport\TrafficScatterPoint;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SalesComparisonReport
{
    private ?SalesComparisonData $cachedData = null;

    public function __construct(
        public readonly MetricsQuery $query,
    ) {}

    public static function for(MetricsQuery $query): self
    {
        return new self($query);
    }

    public function data(): SalesComparisonData
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        $filters = $this->query->filters();
        $period = $filters->period;
        $comparisonPeriod = $filters->comparison;
        $branchIds = $this->query->branchIds();
        $channel = $filters->channel;

        $branchModels = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        // 1. Fetch Current and Comparison Data
        $currentBranchKpis = $this->fetchBranchMetrics($period, $branchIds, $channel);
        $comparisonBranchKpis = $comparisonPeriod !== null
            ? $this->fetchBranchMetrics($comparisonPeriod, $branchIds, $channel)
            : [];

        // 2. Overview Stats (headline cards)
        $overview = $this->buildOverview(
            period: $period,
            comparisonPeriod: $comparisonPeriod,
            branchIds: $branchIds,
            channel: $channel,
            currentBranchKpis: $currentBranchKpis,
            comparisonBranchKpis: $comparisonBranchKpis,
        );

        // 3. Side-by-side KPI Matrix
        $matrix = $this->buildMatrix(
            branchIds: $branchIds,
            branchModels: $branchModels,
            currentKpis: $currentBranchKpis,
            comparisonKpis: $comparisonBranchKpis,
            period: $period,
            comparisonPeriod: $comparisonPeriod,
            channel: $channel,
        );

        // 4. Traffic vs Basket Scatter
        $scatterResult = $this->buildScatter(
            branchIds: $branchIds,
            branchModels: $branchModels,
            currentKpis: $currentBranchKpis,
        );

        // 5. Sales Trend
        [$trendAbsolute, $trendIndexed] = $this->buildTrends(
            period: $period,
            branchIds: $branchIds,
            branchModels: $branchModels,
            channel: $channel,
        );

        // 6. Growth Decomposition Table
        $growthDecomposition = $this->buildGrowthDecomposition(
            branchIds: $branchIds,
            branchModels: $branchModels,
            currentKpis: $currentBranchKpis,
            comparisonKpis: $comparisonBranchKpis,
        );

        // 7. Channel & Payment Mix
        $channelMix = $this->buildChannelAndPaymentMix(
            period: $period,
            branchIds: $branchIds,
            branchModels: $branchModels,
        );

        // 8. Discount Intensity
        $discountIntensity = $this->buildDiscountIntensity(
            branchIds: $branchIds,
            branchModels: $branchModels,
            currentKpis: $currentBranchKpis,
            comparisonKpis: $comparisonBranchKpis,
        );

        $this->cachedData = new SalesComparisonData(
            overview: $overview,
            matrix: $matrix,
            scatter: $scatterResult['points'],
            medianOrdersPerDay: $scatterResult['medianOrders'],
            medianAvgTicket: $scatterResult['medianTicket'],
            trendAbsolute: $trendAbsolute,
            trendIndexed: $trendIndexed,
            growthDecomposition: $growthDecomposition,
            channelMix: $channelMix,
            discountIntensity: $discountIntensity,
        );

        return $this->cachedData;
    }

    /**
     * @param  list<int>  $branchIds
     * @return array<int, array{
     *     sales: float,
     *     orders: int,
     *     avg_ticket: ?float,
     *     gross_sales: float,
     *     discounts: float,
     *     net_sales: float,
     *     returns_amount: float,
     *     cancelled_count: int,
     *     cancel_rate: ?float,
     *     return_rate: ?float,
     *     days_with_data: int
     * }>
     */
    private function fetchBranchMetrics(Period $period, array $branchIds, ?string $channel): array
    {
        $result = [];
        foreach ($branchIds as $id) {
            $result[$id] = [
                'sales' => 0.0,
                'orders' => 0,
                'avg_ticket' => null,
                'gross_sales' => 0.0,
                'discounts' => 0.0,
                'net_sales' => 0.0,
                'returns_amount' => 0.0,
                'cancelled_count' => 0,
                'cancel_rate' => null,
                'return_rate' => null,
                'days_with_data' => 0,
            ];
        }

        if (empty($branchIds)) {
            return $result;
        }

        $rows = $this->query->scoped('agg_branch_daily', $period)->get();

        if ($channel !== null && $channel !== '') {
            $channelOrders = [];
            $channelNetSales = [];
            $daysPerBranch = [];

            foreach ($branchIds as $id) {
                $channelOrders[$id] = 0;
                $channelNetSales[$id] = 0.0;
                $daysPerBranch[$id] = [];
            }

            foreach ($rows as $row) {
                $bId = (int) $row->branch_id;
                $salesByType = json_decode((string) ($row->sales_by_type ?? '{}'), true);

                if (! is_array($salesByType) || ! isset($salesByType[$channel])) {
                    continue;
                }

                $typeData = $salesByType[$channel];
                $orders = (int) ($typeData['orders'] ?? 0);
                $netSales = (float) ($typeData['net_sales'] ?? 0.0);

                if ($orders > 0 || $netSales > 0.0) {
                    $daysPerBranch[$bId][$row->business_date] = true;
                }

                $channelOrders[$bId] += $orders;
                $channelNetSales[$bId] += $netSales;
            }

            foreach ($branchIds as $bId) {
                $orders = $channelOrders[$bId];
                $netSales = $channelNetSales[$bId];
                $daysCount = count($daysPerBranch[$bId]);

                $result[$bId] = [
                    'sales' => $netSales,
                    'orders' => $orders,
                    'avg_ticket' => $orders > 0 ? ($netSales / $orders) : null,
                    'gross_sales' => 0.0,
                    'discounts' => 0.0,
                    'net_sales' => $netSales,
                    'returns_amount' => 0.0,
                    'cancelled_count' => 0,
                    'cancel_rate' => null,
                    'return_rate' => null,
                    'days_with_data' => $daysCount,
                ];
            }

            return $result;
        }

        // Standard KPI aggregation when no channel is specified
        $byBranchKpis = $this->query->byBranch($period);

        foreach ($branchIds as $bId) {
            $kpi = $byBranchKpis[$bId] ?? KpiValues::zero();

            $result[$bId] = [
                'sales' => $kpi->net_sales_after_returns,
                'orders' => $kpi->orders_count,
                'avg_ticket' => $kpi->avgTicket(),
                'gross_sales' => $kpi->gross_sales,
                'discounts' => $kpi->discounts,
                'net_sales' => $kpi->net_sales,
                'returns_amount' => $kpi->returns_amount,
                'cancelled_count' => $kpi->cancelled_count,
                'cancel_rate' => $kpi->cancelRate(),
                'return_rate' => $kpi->returnRate(),
                'days_with_data' => $kpi->days_with_data,
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $branchIds
     * @param  array<int, mixed>  $currentBranchKpis
     * @param  array<int, mixed>  $comparisonBranchKpis
     * @return array<string, array{value: float|int|null, comparison: float|int|null, sparkline?: list<float|int>}>
     */
    private function buildOverview(
        Period $period,
        ?Period $comparisonPeriod,
        array $branchIds,
        ?string $channel,
        array $currentBranchKpis,
        array $comparisonBranchKpis,
    ): array {
        if ($channel !== null && $channel !== '') {
            $totalSales = array_sum(array_column($currentBranchKpis, 'sales'));
            $totalOrders = array_sum(array_column($currentBranchKpis, 'orders'));
            $avgTicket = $totalOrders > 0 ? ($totalSales / $totalOrders) : null;

            $compSales = ! empty($comparisonBranchKpis) ? array_sum(array_column($comparisonBranchKpis, 'sales')) : null;
            $compOrders = ! empty($comparisonBranchKpis) ? array_sum(array_column($comparisonBranchKpis, 'orders')) : null;
            $compAvgTicket = ($compOrders !== null && $compOrders > 0) ? ($compSales / $compOrders) : null;

            return [
                'sales' => ['value' => $totalSales, 'comparison' => $compSales, 'sparkline' => []],
                'orders' => ['value' => $totalOrders, 'comparison' => $compOrders, 'sparkline' => []],
                'avg_ticket' => ['value' => $avgTicket, 'comparison' => $compAvgTicket],
                'discounts' => ['value' => 0.0, 'comparison' => null],
                'cancel_rate' => ['value' => null, 'comparison' => null],
                'return_rate' => ['value' => null, 'comparison' => null],
            ];
        }

        $totals = $this->query->totals($period);
        $compTotals = $comparisonPeriod !== null ? $this->query->totals($comparisonPeriod) : null;

        // Sparklines from daily series
        $sparklineSales = [];
        $sparklineOrders = [];
        $series = $this->query->series(Granularity::day, $period, perBranch: false);
        foreach ($series as $kpiVal) {
            $sparklineSales[] = (float) round($kpiVal->net_sales_after_returns, 0);
            $sparklineOrders[] = (int) $kpiVal->orders_count;
        }

        return [
            'sales' => [
                'value' => $totals->net_sales_after_returns,
                'comparison' => $compTotals?->net_sales_after_returns,
                'sparkline' => $sparklineSales,
            ],
            'orders' => [
                'value' => $totals->orders_count,
                'comparison' => $compTotals?->orders_count,
                'sparkline' => $sparklineOrders,
            ],
            'avg_ticket' => [
                'value' => $totals->avgTicket(),
                'comparison' => $compTotals?->avgTicket(),
            ],
            'discounts' => [
                'value' => $totals->discounts,
                'comparison' => $compTotals?->discounts,
            ],
            'cancel_rate' => [
                'value' => $totals->cancelRate(),
                'comparison' => $compTotals?->cancelRate(),
            ],
            'return_rate' => [
                'value' => $totals->returnRate(),
                'comparison' => $compTotals?->returnRate(),
            ],
        ];
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branchModels
     * @param  array<int, mixed>  $currentKpis
     * @param  array<int, mixed>  $comparisonKpis
     * @return list<SalesKpiMatrixRow>
     */
    private function buildMatrix(
        array $branchIds,
        Collection $branchModels,
        array $currentKpis,
        array $comparisonKpis,
        Period $period,
        ?Period $comparisonPeriod,
        ?string $channel,
    ): array {
        $rows = [];

        foreach ($branchIds as $bId) {
            $branch = $branchModels->get($bId);
            $branchName = $branch !== null ? $branch->name : "الفرع #{$bId}";

            $curr = $currentKpis[$bId] ?? [
                'sales' => 0.0,
                'orders' => 0,
                'avg_ticket' => null,
                'discounts' => 0.0,
                'cancel_rate' => null,
                'return_rate' => null,
                'days_with_data' => 0,
            ];

            $comp = $comparisonKpis[$bId] ?? null;

            $rows[] = new SalesKpiMatrixRow(
                branchId: $bId,
                branchName: $branchName,
                isGroupAverage: false,
                sales: $curr['sales'],
                salesComparison: $comp !== null ? $comp['sales'] : null,
                salesGrowth: $comp !== null ? Kpi::growth($curr['sales'], $comp['sales']) : null,
                orders: $curr['orders'],
                ordersComparison: $comp !== null ? $comp['orders'] : null,
                ordersGrowth: $comp !== null ? Kpi::growth((float) $curr['orders'], (float) $comp['orders']) : null,
                avgTicket: $curr['avg_ticket'],
                avgTicketComparison: $comp !== null ? $comp['avg_ticket'] : null,
                avgTicketGrowth: $comp !== null ? Kpi::growth($curr['avg_ticket'], $comp['avg_ticket']) : null,
                discounts: $curr['discounts'],
                discountsComparison: $comp !== null ? $comp['discounts'] : null,
                discountsGrowth: $comp !== null ? Kpi::growth($curr['discounts'], $comp['discounts']) : null,
                cancelRate: $curr['cancel_rate'],
                cancelRateComparison: $comp !== null ? $comp['cancel_rate'] : null,
                cancelRateGrowth: $comp !== null ? Kpi::growth($curr['cancel_rate'], $comp['cancel_rate']) : null,
                returnRate: $curr['return_rate'],
                returnRateComparison: $comp !== null ? $comp['return_rate'] : null,
                returnRateGrowth: $comp !== null ? Kpi::growth($curr['return_rate'], $comp['return_rate']) : null,
                daysWithData: $curr['days_with_data'],
                daysWithDataComparison: $comp !== null ? $comp['days_with_data'] : null,
            );
        }

        // Group Average row ("متوسط المجموعة")
        $groupRow = $this->calculateGroupAverage(
            period: $period,
            comparisonPeriod: $comparisonPeriod,
            channel: $channel,
        );

        if ($groupRow !== null) {
            $rows[] = $groupRow;
        }

        return $rows;
    }

    private function calculateGroupAverage(
        Period $period,
        ?Period $comparisonPeriod,
        ?string $channel,
    ): ?SalesKpiMatrixRow {
        $user = $this->query->user();
        if ($user->organization_id === null) {
            return null;
        }

        if (! $this->query->canSeeGroup()) {
            return null;
        }

        $orgId = (int) $user->organization_id;
        $filters = $this->query->filters();

        $currentOrgMetrics = $this->fetchOrgAggregateMetrics($period, $orgId, $filters->restaurantId, $channel);
        if ($currentOrgMetrics === null) {
            return null;
        }

        $compOrgMetrics = $comparisonPeriod !== null
            ? $this->fetchOrgAggregateMetrics($comparisonPeriod, $orgId, $filters->restaurantId, $channel)
            : null;

        return new SalesKpiMatrixRow(
            branchId: null,
            branchName: 'متوسط المجموعة',
            isGroupAverage: true,
            sales: $currentOrgMetrics['sales'],
            salesComparison: $compOrgMetrics !== null ? $compOrgMetrics['sales'] : null,
            salesGrowth: $compOrgMetrics !== null ? Kpi::growth($currentOrgMetrics['sales'], $compOrgMetrics['sales']) : null,
            orders: (int) round($currentOrgMetrics['orders']),
            ordersComparison: $compOrgMetrics !== null ? (int) round($compOrgMetrics['orders']) : null,
            ordersGrowth: $compOrgMetrics !== null ? Kpi::growth((float) $currentOrgMetrics['orders'], (float) $compOrgMetrics['orders']) : null,
            avgTicket: $currentOrgMetrics['avg_ticket'],
            avgTicketComparison: $compOrgMetrics !== null ? $compOrgMetrics['avg_ticket'] : null,
            avgTicketGrowth: $compOrgMetrics !== null ? Kpi::growth($currentOrgMetrics['avg_ticket'], $compOrgMetrics['avg_ticket']) : null,
            discounts: $currentOrgMetrics['discounts'],
            discountsComparison: $compOrgMetrics !== null ? $compOrgMetrics['discounts'] : null,
            discountsGrowth: $compOrgMetrics !== null ? Kpi::growth($currentOrgMetrics['discounts'], $compOrgMetrics['discounts']) : null,
            cancelRate: $currentOrgMetrics['cancel_rate'],
            cancelRateComparison: $compOrgMetrics !== null ? $compOrgMetrics['cancel_rate'] : null,
            cancelRateGrowth: $compOrgMetrics !== null ? Kpi::growth($currentOrgMetrics['cancel_rate'], $compOrgMetrics['cancel_rate']) : null,
            returnRate: $currentOrgMetrics['return_rate'],
            returnRateComparison: $compOrgMetrics !== null ? $compOrgMetrics['return_rate'] : null,
            returnRateGrowth: $compOrgMetrics !== null ? Kpi::growth($currentOrgMetrics['return_rate'], $compOrgMetrics['return_rate']) : null,
            daysWithData: (int) round($currentOrgMetrics['days_with_data']),
            daysWithDataComparison: $compOrgMetrics !== null ? (int) round($compOrgMetrics['days_with_data']) : null,
        );
    }

    /**
     * @return array{
     *     sales: float,
     *     orders: float,
     *     avg_ticket: ?float,
     *     discounts: float,
     *     cancel_rate: ?float,
     *     return_rate: ?float,
     *     days_with_data: float,
     *     branch_ids: list<int>
     * }|null
     */
    private function fetchOrgAggregateMetrics(
        Period $period,
        int $organizationId,
        ?int $restaurantId,
        ?string $channel,
    ): ?array {
        $query = DB::table('agg_branch_daily')
            ->where('organization_id', $organizationId)
            ->whereBetween('business_date', [$period->from->toDateString(), $period->to->toDateString()]);

        if ($restaurantId !== null) {
            $query->where('restaurant_id', $restaurantId);
        }

        $rows = $query->get();
        if ($rows->isEmpty()) {
            return null;
        }

        /** @var list<int> $branchIds */
        $branchIds = $rows->pluck('branch_id')->unique()->map(fn ($id): int => (int) $id)->values()->all();
        $branchCount = max(1, count($branchIds));

        if ($channel !== null && $channel !== '') {
            $totalOrders = 0;
            $totalNetSales = 0.0;
            $totalDays = 0;

            foreach ($branchIds as $bId) {
                $branchRows = $rows->where('branch_id', $bId);
                $bDays = 0;
                foreach ($branchRows as $row) {
                    $salesByType = json_decode((string) ($row->sales_by_type ?? '{}'), true);
                    if (is_array($salesByType) && isset($salesByType[$channel])) {
                        $orders = (int) ($salesByType[$channel]['orders'] ?? 0);
                        $netSales = (float) ($salesByType[$channel]['net_sales'] ?? 0.0);
                        if ($orders > 0 || $netSales > 0.0) {
                            $bDays++;
                            $totalOrders += $orders;
                            $totalNetSales += $netSales;
                        }
                    }
                }
                $totalDays += $bDays;
            }

            return [
                'sales' => round($totalNetSales / $branchCount, 2),
                'orders' => round((float) $totalOrders / $branchCount, 1),
                'avg_ticket' => $totalOrders > 0 ? round($totalNetSales / $totalOrders, 2) : null,
                'discounts' => 0.0,
                'cancel_rate' => null,
                'return_rate' => null,
                'days_with_data' => round((float) $totalDays / $branchCount, 1),
                'branch_ids' => $branchIds,
            ];
        }

        $totalSales = (float) $rows->sum('net_sales_after_returns');
        $totalOrders = (int) $rows->sum('orders_count');
        $totalCancelled = (int) $rows->sum('cancelled_count');
        $totalNetSales = (float) $rows->sum('net_sales');
        $totalReturns = (float) $rows->sum('returns_amount');
        $totalDiscounts = (float) $rows->sum('discounts');
        $totalDays = $rows->count();

        $avgTicket = $totalOrders > 0 ? ($totalNetSales / $totalOrders) : null;
        $cancelRate = ($totalOrders + $totalCancelled) > 0 ? ($totalCancelled / ($totalOrders + $totalCancelled)) : null;
        $returnRate = $totalNetSales > 0.0 ? ($totalReturns / $totalNetSales) : null;

        return [
            'sales' => round($totalSales / $branchCount, 2),
            'orders' => round((float) $totalOrders / $branchCount, 1),
            'avg_ticket' => $avgTicket !== null ? round($avgTicket, 2) : null,
            'discounts' => round($totalDiscounts / $branchCount, 2),
            'cancel_rate' => $cancelRate,
            'return_rate' => $returnRate,
            'days_with_data' => round((float) $totalDays / $branchCount, 1),
            'branch_ids' => $branchIds,
        ];
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branchModels
     * @param  array<int, mixed>  $currentKpis
     * @return array{
     *     points: list<TrafficScatterPoint>,
     *     medianOrders: ?float,
     *     medianTicket: ?float
     * }
     */
    private function buildScatter(
        array $branchIds,
        Collection $branchModels,
        array $currentKpis,
    ): array {
        $points = [];
        $ordersList = [];
        $ticketList = [];

        foreach ($branchIds as $bId) {
            $branch = $branchModels->get($bId);
            $branchName = $branch !== null ? $branch->name : "الفرع #{$bId}";
            $kpi = $currentKpis[$bId] ?? null;

            if ($kpi === null) {
                continue;
            }

            $days = max(1, (int) $kpi['days_with_data']);
            $ordersPerDay = round($kpi['orders'] / $days, 2);
            $avgTicket = $kpi['avg_ticket'] !== null ? round($kpi['avg_ticket'], 2) : 0.0;
            $sales = (float) round($kpi['sales'], 2);

            $points[] = new TrafficScatterPoint(
                branchId: $bId,
                branchName: $branchName,
                ordersPerDay: $ordersPerDay,
                avgTicket: $avgTicket,
                sales: $sales,
            );

            $ordersList[] = $ordersPerDay;
            if ($avgTicket > 0.0) {
                $ticketList[] = $avgTicket;
            }
        }

        return [
            'points' => $points,
            'medianOrders' => $this->calculateMedian($ordersList),
            'medianTicket' => $this->calculateMedian($ticketList),
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function calculateMedian(array $values): ?float
    {
        if (empty($values)) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);

        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return (float) round(($values[$middle - 1] + $values[$middle]) / 2, 2);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branchModels
     * @return array{0: SalesTrendData, 1: SalesTrendData}
     */
    private function buildTrends(
        Period $period,
        array $branchIds,
        Collection $branchModels,
        ?string $channel,
    ): array {
        $granularity = $this->query->filters()->granularity;

        // Establish bucket dates
        $bucketKeys = [];
        foreach ($period->dates() as $date) {
            $start = $granularity->bucketStart($date)->toDateString();
            $bucketKeys[$start] = true;
        }
        $buckets = array_keys($bucketKeys);

        $branchBuckets = [];
        foreach ($branchIds as $bId) {
            foreach ($buckets as $b) {
                $branchBuckets[$bId][$b] = 0.0;
            }
        }

        $rows = $this->query->scoped('agg_branch_daily', $period)->get();

        foreach ($rows as $row) {
            $bId = (int) $row->branch_id;
            $date = CarbonImmutable::parse($row->business_date);
            $bucket = $granularity->bucketStart($date)->toDateString();

            if (! isset($branchBuckets[$bId][$bucket])) {
                continue;
            }

            if ($channel !== null && $channel !== '') {
                $salesByType = json_decode((string) ($row->sales_by_type ?? '{}'), true);
                if (is_array($salesByType) && isset($salesByType[$channel])) {
                    $branchBuckets[$bId][$bucket] += (float) ($salesByType[$channel]['net_sales'] ?? 0.0);
                }
            } else {
                $branchBuckets[$bId][$bucket] += (float) $row->net_sales_after_returns;
            }
        }

        $datasetsAbsolute = [];
        $datasetsIndexed = [];

        foreach ($branchIds as $bId) {
            $branch = $branchModels->get($bId);
            $branchName = $branch !== null ? $branch->name : "الفرع #{$bId}";
            $rawValues = array_values($branchBuckets[$bId]);

            // Absolute dataset
            $datasetsAbsolute[] = [
                'branch_id' => $bId,
                'label' => $branchName,
                'data' => array_map(fn ($v): float => (float) round($v, 2), $rawValues),
            ];

            // Indexed dataset (first non-zero bucket = 100)
            $firstNonZero = null;
            foreach ($rawValues as $v) {
                if ($v > 0.0) {
                    $firstNonZero = $v;
                    break;
                }
            }

            $indexedValues = [];
            foreach ($rawValues as $v) {
                if ($firstNonZero === null || $firstNonZero == 0.0) {
                    $indexedValues[] = 0.0;
                } else {
                    $indexedValues[] = (float) round(($v / $firstNonZero) * 100, 1);
                }
            }

            $datasetsIndexed[] = [
                'branch_id' => $bId,
                'label' => $branchName,
                'data' => $indexedValues,
            ];
        }

        return [
            new SalesTrendData(labels: $buckets, datasets: $datasetsAbsolute),
            new SalesTrendData(labels: $buckets, datasets: $datasetsIndexed),
        ];
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branchModels
     * @param  array<int, mixed>  $currentKpis
     * @param  array<int, mixed>  $comparisonKpis
     * @return list<GrowthDecompositionRow>
     */
    private function buildGrowthDecomposition(
        array $branchIds,
        Collection $branchModels,
        array $currentKpis,
        array $comparisonKpis,
    ): array {
        $rows = [];

        foreach ($branchIds as $bId) {
            $branch = $branchModels->get($bId);
            $branchName = $branch !== null ? $branch->name : "الفرع #{$bId}";

            $curr = $currentKpis[$bId] ?? null;
            $comp = $comparisonKpis[$bId] ?? null;

            if ($curr === null || $comp === null) {
                continue;
            }

            $o0 = (int) $comp['orders'];
            $o1 = (int) $curr['orders'];
            $t0 = (float) ($comp['avg_ticket'] ?? 0.0);
            $t1 = (float) ($curr['avg_ticket'] ?? 0.0);

            $n0 = (float) $comp['net_sales'];
            $n1 = (float) $curr['net_sales'];
            $deltaNetSales = round($n1 - $n0, 2);

            // Exact decomposition: (o1 - o0) * t0 + (t1 - t0) * o1 == deltaNetSales
            $ordersEffect = round(($o1 - $o0) * $t0, 2);
            $ticketEffect = round(($t1 - $t0) * $o1, 2);

            // Guard against floating point micro-rounding so they sum exactly
            $diff = round($deltaNetSales - ($ordersEffect + $ticketEffect), 2);
            if ($diff != 0.0) {
                $ticketEffect = round($ticketEffect + $diff, 2);
            }

            $r0 = (float) $comp['returns_amount'];
            $r1 = (float) $curr['returns_amount'];
            $returnsEffect = round($r0 - $r1, 2); // An increase in returns reduces sales

            $s0 = (float) $comp['sales'];
            $s1 = (float) $curr['sales'];
            $deltaSales = round($s1 - $s0, 2);

            $rows[] = new GrowthDecompositionRow(
                branchId: $bId,
                branchName: $branchName,
                orders0: $o0,
                orders1: $o1,
                ticket0: round($t0, 2),
                ticket1: round($t1, 2),
                netSales0: round($n0, 2),
                netSales1: round($n1, 2),
                deltaNetSales: $deltaNetSales,
                ordersEffect: $ordersEffect,
                ticketEffect: $ticketEffect,
                returns0: round($r0, 2),
                returns1: round($r1, 2),
                returnsEffect: $returnsEffect,
                sales0: round($s0, 2),
                sales1: round($s1, 2),
                deltaSales: $deltaSales,
            );
        }

        return $rows;
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branchModels
     */
    private function buildChannelAndPaymentMix(
        Period $period,
        array $branchIds,
        Collection $branchModels,
    ): ChannelMixData {
        $branchNames = [];
        $branchChannelSales = [];
        $branchPaymentSales = [];
        $allChannels = [];
        $allPayments = [];

        $channelLabels = [
            'dine_in' => 'صالة (Dine-in)',
            'takeaway' => 'سفري (Takeaway)',
            'delivery' => 'توصيل (Delivery)',
            'drive_thru' => 'طلب السيارة (Drive-thru)',
            'online' => 'أونلاين (Online)',
        ];

        $paymentLabels = [
            'cash' => 'نقداً (Cash)',
            'card' => 'بطاقة (Card)',
            'talabat_card' => 'بطاقة طلبات (Talabat)',
            'other' => 'أخرى',
        ];

        $rows = $this->query->scoped('agg_branch_daily', $period)->get();

        foreach ($branchIds as $bId) {
            $branch = $branchModels->get($bId);
            $branchNames[$bId] = $branch !== null ? $branch->name : "الفرع #{$bId}";
            $branchChannelSales[$bId] = [];
            $branchPaymentSales[$bId] = [];
        }

        foreach ($rows as $row) {
            $bId = (int) $row->branch_id;
            if (! isset($branchChannelSales[$bId])) {
                continue;
            }

            // Channels from sales_by_type
            $salesByType = json_decode((string) ($row->sales_by_type ?? '{}'), true);
            if (is_array($salesByType)) {
                foreach ($salesByType as $type => $data) {
                    $typeStr = (string) $type;
                    $netSales = (float) ($data['net_sales'] ?? 0.0);
                    $branchChannelSales[$bId][$typeStr] = ($branchChannelSales[$bId][$typeStr] ?? 0.0) + $netSales;
                    $allChannels[$typeStr] = true;
                }
            }

            // Payments from sales_by_method
            $salesByMethod = json_decode((string) ($row->sales_by_method ?? '{}'), true);
            if (is_array($salesByMethod)) {
                foreach ($salesByMethod as $method => $amount) {
                    $methodStr = (string) $method;
                    $amountVal = (float) $amount;
                    $branchPaymentSales[$bId][$methodStr] = ($branchPaymentSales[$bId][$methodStr] ?? 0.0) + $amountVal;
                    $allPayments[$methodStr] = true;
                }
            }
        }

        $channelKeys = array_keys($allChannels);
        $paymentKeys = array_keys($allPayments);

        $channelSeries = [];
        foreach ($channelKeys as $ch) {
            $channelSeries[$ch] = [];
            foreach ($branchIds as $bId) {
                $totalForBranch = array_sum($branchChannelSales[$bId]);
                if (! isset($branchChannelSales[$bId][$ch]) || $branchChannelSales[$bId][$ch] == 0.0) {
                    $channelSeries[$ch][] = null; // Spec: empty, not zero if channel not offered
                } else {
                    $ratio = $totalForBranch > 0 ? ($branchChannelSales[$bId][$ch] / $totalForBranch) * 100 : 0.0;
                    $channelSeries[$ch][] = (float) round($ratio, 1);
                }
            }
        }

        $paymentSeries = [];
        foreach ($paymentKeys as $pm) {
            $paymentSeries[$pm] = [];
            foreach ($branchIds as $bId) {
                $totalForBranch = array_sum($branchPaymentSales[$bId]);
                if (! isset($branchPaymentSales[$bId][$pm]) || $branchPaymentSales[$bId][$pm] == 0.0) {
                    $paymentSeries[$pm][] = null;
                } else {
                    $ratio = $totalForBranch > 0 ? ($branchPaymentSales[$bId][$pm] / $totalForBranch) * 100 : 0.0;
                    $paymentSeries[$pm][] = (float) round($ratio, 1);
                }
            }
        }

        return new ChannelMixData(
            branches: array_values($branchNames),
            channels: $channelKeys,
            channelLabels: $channelLabels,
            channelSeries: $channelSeries,
            paymentMethods: $paymentKeys,
            paymentLabels: $paymentLabels,
            paymentSeries: $paymentSeries,
        );
    }

    /**
     * @param  list<int>  $branchIds
     * @param  Collection<int, Branch>  $branchModels
     * @param  array<int, mixed>  $currentKpis
     * @param  array<int, mixed>  $comparisonKpis
     * @return list<DiscountIntensityRow>
     */
    private function buildDiscountIntensity(
        array $branchIds,
        Collection $branchModels,
        array $currentKpis,
        array $comparisonKpis,
    ): array {
        $rows = [];

        foreach ($branchIds as $bId) {
            $branch = $branchModels->get($bId);
            $branchName = $branch !== null ? $branch->name : "الفرع #{$bId}";

            $curr = $currentKpis[$bId] ?? null;
            $comp = $comparisonKpis[$bId] ?? null;

            if ($curr === null) {
                continue;
            }

            $grossSales = (float) $curr['gross_sales'];
            $discounts = (float) $curr['discounts'];
            $discountPct = $grossSales > 0 ? ($discounts / $grossSales) : null;

            $compGross = $comp !== null ? (float) $comp['gross_sales'] : null;
            $compDisc = $comp !== null ? (float) $comp['discounts'] : null;
            $compDiscountPct = ($compGross !== null && $compGross > 0) ? ($compDisc / $compGross) : null;

            $deltaDiscountPct = ($discountPct !== null && $compDiscountPct !== null)
                ? round(($discountPct - $compDiscountPct) * 100, 1)
                : null;

            $rows[] = new DiscountIntensityRow(
                branchId: $bId,
                branchName: $branchName,
                grossSales: round($grossSales, 2),
                discounts: round($discounts, 2),
                discountPct: $discountPct !== null ? round($discountPct, 4) : null,
                compGrossSales: $compGross !== null ? round($compGross, 2) : null,
                compDiscounts: $compDisc !== null ? round($compDisc, 2) : null,
                compDiscountPct: $compDiscountPct !== null ? round($compDiscountPct, 4) : null,
                deltaDiscountPct: $deltaDiscountPct,
            );
        }

        return $rows;
    }
}
