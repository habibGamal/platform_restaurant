<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetBranchTableRow;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetBurnUpSeries;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetHistoryCell;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetHistoryRow;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetOverviewMetric;
use App\Domain\Metrics\Reports\TargetsVsActualReport\TargetsVsActualData;
use App\Domain\Targets\BranchTargetStatus;
use App\Domain\Targets\TargetAchievement;
use App\Domain\Targets\TargetDistributor;
use App\Models\Branch;
use App\Models\Target;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class TargetsVsActualReport
{
    private const array METRICS = [
        'net_sales' => 'صافي المبيعات (K06)',
        'net_profit' => 'صافي الربح (K14)',
        'orders' => 'عدد الطلبات (K01)',
        'food_cost_pct' => 'نسبة تكلفة الخامات (K16)',
    ];

    private ?TargetsVsActualData $cachedData = null;

    public function __construct(
        public readonly MetricsQuery $query,
        public readonly ?CarbonImmutable $asOf = null,
    ) {}

    public static function for(MetricsQuery $query, ?CarbonImmutable $asOf = null): self
    {
        return new self($query, $asOf);
    }

    public function data(): TargetsVsActualData
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        return $this->cachedData = $this->buildData();
    }

    private function resolveToday(): CarbonImmutable
    {
        if ($this->asOf !== null) {
            return $this->asOf->startOfDay();
        }

        $periodTo = $this->query->filters()->period->to;
        $currentDay = CarbonImmutable::now('Africa/Cairo')->startOfDay();

        return $periodTo->isBefore($currentDay) ? $periodTo : $currentDay;
    }

    private function buildData(): TargetsVsActualData
    {
        $today = $this->resolveToday();
        $branchIds = $this->query->branchIds();

        /** @var Collection<int, Branch> $branchModels */
        $branchModels = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        $canSeeGroup = $this->query->canSeeGroup();
        $canSeeOtherBranchNames = $this->query->canSeeOtherBranchNames();

        // 1. Gather TargetAchievement statuses for each metric
        /** @var array<string, list<BranchTargetStatus>> $metricStatuses */
        $metricStatuses = [];
        foreach (array_keys(self::METRICS) as $metric) {
            $metricStatuses[$metric] = TargetAchievement::for($this->query, $metric, $today);
        }

        // 2. Build Overview
        $overview = $this->buildOverview($metricStatuses, $today, $canSeeGroup);

        // 3. Build Branch Table Rows
        $branchRows = $this->buildBranchRows($metricStatuses, $branchModels, $overview, $canSeeGroup, $canSeeOtherBranchNames);

        // 4. Build Burn-up Series
        $burnUpSeries = $this->buildBurnUpSeries($branchModels, $today, $canSeeGroup, $canSeeOtherBranchNames);

        // 5. Build History (last 12 months)
        [$historyMonths, $historyMonthLabels, $historyRows] = $this->buildHistory($branchModels, $today, $canSeeGroup, $canSeeOtherBranchNames);

        return new TargetsVsActualData(
            overview: $overview,
            branchRows: $branchRows,
            burnUpSeries: $burnUpSeries,
            historyMonths: $historyMonths,
            historyMonthLabels: $historyMonthLabels,
            historyRows: $historyRows,
        );
    }

    /**
     * @param  array<string, list<BranchTargetStatus>>  $metricStatuses
     * @return array<string, TargetOverviewMetric>
     */
    private function buildOverview(array $metricStatuses, CarbonImmutable $today, bool $canSeeGroup): array
    {
        $overview = [];

        foreach (self::METRICS as $metric => $label) {
            $statuses = $metricStatuses[$metric] ?? [];
            $totalBranches = count($statuses);
            $withTarget = array_values(array_filter($statuses, fn (BranchTargetStatus $s): bool => $s->targetMonth !== null));
            $branchesWithTarget = count($withTarget);

            $sparkline = $this->fetchSparkline($metric, $today, $canSeeGroup);

            if (! $canSeeGroup) {
                // Single-branch scope for branch managers
                if (count($statuses) > 0) {
                    $s = $statuses[0];
                    $overview[$metric] = new TargetOverviewMetric(
                        metric: $metric,
                        label: $label,
                        targetMonth: $s->targetMonth,
                        targetToDate: $s->targetToDate,
                        actualToDate: $s->actualToDate,
                        achievementToDate: $s->achievementToDate,
                        monthAchievement: $s->monthAchievement,
                        gap: $s->gap,
                        projected: $s->projected,
                        projectedAchievement: $s->projectedAchievement,
                        status: $s->status,
                        branchesWithTarget: $s->targetMonth !== null ? 1 : 0,
                        branchesTotal: 1,
                        note: null,
                        sparkline: $sparkline,
                    );
                } else {
                    $overview[$metric] = new TargetOverviewMetric(
                        metric: $metric,
                        label: $label,
                        targetMonth: null,
                        targetToDate: null,
                        actualToDate: 0.0,
                        achievementToDate: null,
                        monthAchievement: null,
                        gap: null,
                        projected: null,
                        projectedAchievement: null,
                        status: 'no_target',
                        branchesWithTarget: 0,
                        branchesTotal: 0,
                        note: null,
                        sparkline: [],
                    );
                }

                continue;
            }

            // Group-level view
            if ($branchesWithTarget === 0) {
                $overview[$metric] = new TargetOverviewMetric(
                    metric: $metric,
                    label: $label,
                    targetMonth: null,
                    targetToDate: null,
                    actualToDate: 0.0,
                    achievementToDate: null,
                    monthAchievement: null,
                    gap: null,
                    projected: null,
                    projectedAchievement: null,
                    status: 'no_target',
                    branchesWithTarget: 0,
                    branchesTotal: $totalBranches,
                    note: 'لم يُحدد مستهدف لأي فرع',
                    sparkline: $sparkline,
                );

                continue;
            }

            $excludedBranches = $totalBranches - $branchesWithTarget;
            $note = $excludedBranches > 0 ? "تم استثناء {$excludedBranches} فرع ليس له مستهدف محدد" : null;

            if ($metric === 'food_cost_pct') {
                $branchIdsWithTarget = array_map(fn (BranchTargetStatus $s): int => $s->branchId, $withTarget);
                $branchActuals = $this->fetchBranchActualTotals($branchIdsWithTarget, $today);

                $totalSales = 0.0;
                $totalCogs = 0.0;
                $weightedTargetSum = 0.0;

                foreach ($withTarget as $s) {
                    $kpi = $branchActuals[$s->branchId] ?? KpiValues::zero();
                    $sales = max(0.0, $kpi->net_sales_after_returns);
                    $cogs = max(0.0, $kpi->cogs - $kpi->returned_cogs_recovered);

                    $totalSales += $sales;
                    $totalCogs += $cogs;
                    $weightedTargetSum += ($s->targetMonth ?? 0.0) * $sales;
                }

                $targetMonth = $totalSales > 0.0 ? ($weightedTargetSum / $totalSales) : (array_sum(array_map(fn ($s) => $s->targetMonth ?? 0.0, $withTarget)) / $branchesWithTarget);
                $targetToDate = $targetMonth;
                $actualToDate = $totalSales > 0.0 ? ($totalCogs / $totalSales) : 0.0;
                $gap = $targetToDate - $actualToDate;
                $projected = $actualToDate;
                $achievementToDate = ($targetToDate != 0.0) ? ($actualToDate / $targetToDate) : null;
                $status = ($actualToDate <= $targetMonth) ? 'within' : 'above';

                $overview[$metric] = new TargetOverviewMetric(
                    metric: $metric,
                    label: $label,
                    targetMonth: round($targetMonth, 4),
                    targetToDate: round($targetToDate, 4),
                    actualToDate: round($actualToDate, 4),
                    achievementToDate: $achievementToDate,
                    monthAchievement: $achievementToDate,
                    gap: round($gap, 4),
                    projected: round($projected, 4),
                    projectedAchievement: $achievementToDate,
                    status: $status,
                    branchesWithTarget: $branchesWithTarget,
                    branchesTotal: $totalBranches,
                    note: $note,
                    sparkline: $sparkline,
                );

                continue;
            }

            // Normal cumulative metrics: net_sales, net_profit, orders
            $targetMonth = array_sum(array_map(fn ($s) => $s->targetMonth ?? 0.0, $withTarget));
            $targetToDate = array_sum(array_map(fn ($s) => $s->targetToDate ?? 0.0, $withTarget));
            $actualToDate = array_sum(array_map(fn ($s) => $s->actualToDate, $withTarget));
            $projected = array_sum(array_map(fn ($s) => $s->projected ?? 0.0, $withTarget));

            $achievementToDate = ($targetToDate != 0.0) ? ($actualToDate / $targetToDate) : null;
            $monthAchievement = ($targetMonth != 0.0) ? ($actualToDate / $targetMonth) : null;
            $gap = $targetToDate - $actualToDate;
            $projectedAchievement = ($targetMonth != 0.0) ? ($projected / $targetMonth) : null;

            $status = $this->determineStatus($achievementToDate);

            $overview[$metric] = new TargetOverviewMetric(
                metric: $metric,
                label: $label,
                targetMonth: round($targetMonth, 2),
                targetToDate: round($targetToDate, 2),
                actualToDate: round($actualToDate, 2),
                achievementToDate: $achievementToDate,
                monthAchievement: $monthAchievement,
                gap: round($gap, 2),
                projected: round($projected, 2),
                projectedAchievement: $projectedAchievement,
                status: $status,
                branchesWithTarget: $branchesWithTarget,
                branchesTotal: $totalBranches,
                note: $note,
                sparkline: $sparkline,
            );
        }

        return $overview;
    }

    /**
     * @param  array<string, list<BranchTargetStatus>>  $metricStatuses
     * @param  Collection<int, Branch>  $branchModels
     * @param  array<string, TargetOverviewMetric>  $overview
     * @return list<TargetBranchTableRow>
     */
    private function buildBranchRows(
        array $metricStatuses,
        Collection $branchModels,
        array $overview,
        bool $canSeeGroup,
        bool $canSeeOtherBranchNames
    ): array {
        $rows = [];
        $branchIds = $this->query->branchIds();

        foreach (self::METRICS as $metric => $metricLabel) {
            $statuses = $metricStatuses[$metric] ?? [];
            $statusByBranch = [];
            foreach ($statuses as $s) {
                $statusByBranch[$s->branchId] = $s;
            }

            foreach ($branchIds as $idx => $bId) {
                $branch = $branchModels->get($bId);
                $branchName = $this->resolveBranchName($branch, $bId, $idx, $canSeeOtherBranchNames);
                $s = $statusByBranch[$bId] ?? null;

                if ($s === null) {
                    continue;
                }

                $rows[] = new TargetBranchTableRow(
                    branchId: $bId,
                    branchName: $branchName,
                    metric: $metric,
                    metricLabel: $metricLabel,
                    targetMonth: $s->targetMonth,
                    targetToDate: $s->targetToDate,
                    actualToDate: $s->actualToDate,
                    achievementToDate: $s->achievementToDate,
                    monthAchievement: $s->monthAchievement,
                    gap: $s->gap,
                    projected: $s->projected,
                    projectedAchievement: $s->projectedAchievement,
                    requiredDailyAverage: $s->requiredDailyAverage,
                    currentDailyAverage: $s->currentDailyAverage,
                    status: $s->status,
                    isGroup: false,
                );
            }

            // Group summary row for each metric (only when canSeeGroup is true and more than 1 branch)
            if ($canSeeGroup && count($branchIds) > 0) {
                $ov = $overview[$metric] ?? null;
                if ($ov !== null && $ov->branchesWithTarget > 0) {
                    $targetStatuses = array_filter($statuses, fn ($s) => $s->targetMonth !== null);
                    $sumRequiredDaily = array_sum(array_map(fn ($s) => $s->requiredDailyAverage ?? 0.0, $targetStatuses));
                    $sumCurrentDaily = array_sum(array_map(fn ($s) => $s->currentDailyAverage ?? 0.0, $targetStatuses));

                    $rows[] = new TargetBranchTableRow(
                        branchId: null,
                        branchName: 'إجمالي المجموعة',
                        metric: $metric,
                        metricLabel: $metricLabel,
                        targetMonth: $ov->targetMonth,
                        targetToDate: $ov->targetToDate,
                        actualToDate: $ov->actualToDate,
                        achievementToDate: $ov->achievementToDate,
                        monthAchievement: $ov->monthAchievement,
                        gap: $ov->gap,
                        projected: $ov->projected,
                        projectedAchievement: $ov->projectedAchievement,
                        requiredDailyAverage: $metric === 'food_cost_pct' ? null : round($sumRequiredDaily, 2),
                        currentDailyAverage: $metric === 'food_cost_pct' ? $ov->actualToDate : round($sumCurrentDaily, 2),
                        status: $ov->status,
                        isGroup: true,
                    );
                }
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Branch>  $branchModels
     * @return array<string, TargetBurnUpSeries>
     */
    private function buildBurnUpSeries(
        Collection $branchModels,
        CarbonImmutable $today,
        bool $canSeeGroup,
        bool $canSeeOtherBranchNames
    ): array {
        $branchIds = $this->query->branchIds();
        $monthStart = $today->startOfMonth()->startOfDay();
        $monthEnd = $today->endOfMonth()->startOfDay();
        $todayStr = $today->toDateString();

        $allDates = [];
        $labels = [];
        $cursor = $monthStart;
        while ($cursor <= $monthEnd) {
            $allDates[] = $cursor->toDateString();
            $labels[] = (string) $cursor->day;
            $cursor = $cursor->addDay();
        }

        $seriesMap = [];

        // 1. Group burn-up series (if canSeeGroup)
        if ($canSeeGroup && count($branchIds) > 0) {
            $groupSeries = $this->computeBurnUpForBranches(
                branchIds: $branchIds,
                metric: 'net_sales',
                entityKey: 'group',
                entityName: 'إجمالي المجموعة',
                allDates: $allDates,
                labels: $labels,
                monthStart: $monthStart,
                todayStr: $todayStr,
            );

            if ($groupSeries !== null) {
                $seriesMap['group'] = $groupSeries;
            }
        }

        // 2. Per-branch burn-up series
        foreach ($branchIds as $idx => $bId) {
            $branch = $branchModels->get($bId);
            $branchName = $this->resolveBranchName($branch, $bId, $idx, $canSeeOtherBranchNames);

            $branchSeries = $this->computeBurnUpForBranches(
                branchIds: [$bId],
                metric: 'net_sales',
                entityKey: 'branch_'.$bId,
                entityName: $branchName,
                allDates: $allDates,
                labels: $labels,
                monthStart: $monthStart,
                todayStr: $todayStr,
            );

            if ($branchSeries !== null) {
                $seriesMap['branch_'.$bId] = $branchSeries;
            }
        }

        return $seriesMap;
    }

    /**
     * @param  list<int>  $branchIds
     * @param  list<string>  $allDates
     * @param  list<string>  $labels
     */
    private function computeBurnUpForBranches(
        array $branchIds,
        string $metric,
        string $entityKey,
        string $entityName,
        array $allDates,
        array $labels,
        CarbonImmutable $monthStart,
        string $todayStr
    ): ?TargetBurnUpSeries {
        // Query targets for these branches
        $targets = Target::query()
            ->whereIn('branch_id', $branchIds)
            ->where('metric', $metric)
            ->where('period_month', $monthStart->toDateString())
            ->get();

        if ($targets->isEmpty()) {
            return null;
        }

        $targetIds = $targets->pluck('id')->all();

        // Get daily target distribution
        $dailyTargetRows = DB::table('target_daily')
            ->whereIn('target_id', $targetIds)
            ->select('date', DB::raw('SUM(value) as total_value'))
            ->groupBy('date')
            ->pluck('total_value', 'date')
            ->all();

        // Fallback to computing distribution directly if target_daily rows are missing
        if (empty($dailyTargetRows)) {
            foreach ($targets as $target) {
                $dist = TargetDistributor::daily($target);
                foreach ($dist as $d => $v) {
                    $dailyTargetRows[$d] = ($dailyTargetRows[$d] ?? 0.0) + $v;
                }
            }
        }

        // Fetch daily actuals from agg_branch_daily
        $dailyActualRows = DB::table('agg_branch_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$monthStart->toDateString(), $todayStr])
            ->select('business_date', DB::raw('SUM(net_sales_after_returns) as total_actual'))
            ->groupBy('business_date')
            ->pluck('total_actual', 'business_date')
            ->all();

        $targetSeries = [];
        $actualSeries = [];
        $cumTarget = 0.0;
        $cumActual = 0.0;
        $targetToDate = 0.0;
        $actualToDate = 0.0;

        foreach ($allDates as $dateStr) {
            $tVal = (float) ($dailyTargetRows[$dateStr] ?? 0.0);
            $cumTarget += $tVal;
            $targetSeries[] = round($cumTarget, 2);

            if ($dateStr <= $todayStr) {
                $aVal = (float) ($dailyActualRows[$dateStr] ?? 0.0);
                $cumActual += $aVal;
                $actualSeries[] = round($cumActual, 2);
                $targetToDate = $cumTarget;
                $actualToDate = $cumActual;
            } else {
                $actualSeries[] = null;
            }
        }

        // Projection series: null before today, starts at actualToDate on todayStr, and projects remaining days
        $projectedSeries = [];
        $paceFactor = ($targetToDate > 0.0) ? ($actualToDate / $targetToDate) : 1.0;
        $remTargetAccum = 0.0;

        foreach ($allDates as $dateStr) {
            if ($dateStr < $todayStr) {
                $projectedSeries[] = null;
            } elseif ($dateStr === $todayStr) {
                $projectedSeries[] = round($actualToDate, 2);
            } else {
                $tVal = (float) ($dailyTargetRows[$dateStr] ?? 0.0);
                $remTargetAccum += $tVal;
                $projectedAtDay = $actualToDate + ($remTargetAccum * $paceFactor);
                $projectedSeries[] = round($projectedAtDay, 2);
            }
        }

        return new TargetBurnUpSeries(
            entityKey: $entityKey,
            entityName: $entityName,
            metric: $metric,
            labels: $labels,
            target: $targetSeries,
            actual: $actualSeries,
            projected: $projectedSeries,
        );
    }

    /**
     * @param  Collection<int, Branch>  $branchModels
     * @return array{0: list<string>, 1: array<string, string>, 2: list<TargetHistoryRow>}
     */
    private function buildHistory(
        Collection $branchModels,
        CarbonImmutable $today,
        bool $canSeeGroup,
        bool $canSeeOtherBranchNames
    ): array {
        $arabicMonths = [
            1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
            5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
            9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر',
        ];

        $branchIds = $this->query->branchIds();
        $currentMonth = $today->startOfMonth()->startOfDay();

        $historyMonths = [];
        $historyMonthLabels = [];
        $cursor = $currentMonth->subMonths(11);

        while ($cursor <= $currentMonth) {
            $key = $cursor->format('Y-m');
            $historyMonths[] = $key;
            $historyMonthLabels[$key] = $arabicMonths[(int) $cursor->format('n')].' '.$cursor->format('Y');
            $cursor = $cursor->addMonth();
        }

        $startMonthDate = $currentMonth->subMonths(11)->toDateString();
        $endMonthDate = $currentMonth->endOfMonth()->toDateString();

        // Query historical targets for net_sales
        $targets = Target::query()
            ->whereIn('branch_id', $branchIds)
            ->where('metric', 'net_sales')
            ->whereBetween('period_month', [$startMonthDate, $endMonthDate])
            ->get();

        $targetMap = [];
        foreach ($targets as $t) {
            $mKey = CarbonImmutable::parse($t->period_month)->format('Y-m');
            $targetMap[$t->branch_id][$mKey] = (float) $t->value;
        }

        // Query historical actual sales from agg_branch_daily
        $actualRows = DB::table('agg_branch_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$startMonthDate, $endMonthDate])
            ->selectRaw('branch_id, DATE_FORMAT(business_date, "%Y-%m") as month_key, SUM(net_sales_after_returns) as total_sales')
            ->groupBy('branch_id', 'month_key')
            ->get();

        $actualMap = [];
        foreach ($actualRows as $r) {
            $actualMap[(int) $r->branch_id][(string) $r->month_key] = (float) $r->total_sales;
        }

        $historyRows = [];

        // Build per-branch history rows
        foreach ($branchIds as $idx => $bId) {
            $branch = $branchModels->get($bId);
            $branchName = $this->resolveBranchName($branch, $bId, $idx, $canSeeOtherBranchNames);

            $cells = [];
            foreach ($historyMonths as $mKey) {
                $targetVal = $targetMap[$bId][$mKey] ?? null;
                $actualVal = $actualMap[$bId][$mKey] ?? 0.0;

                if ($targetVal !== null && $targetVal > 0.0) {
                    $achievement = $actualVal / $targetVal;
                    $status = $this->determineStatus($achievement);
                } else {
                    $achievement = null;
                    $status = 'no_target';
                }

                $cells[$mKey] = new TargetHistoryCell(
                    monthKey: $mKey,
                    target: $targetVal,
                    actual: $actualVal,
                    achievement: $achievement,
                    status: $status,
                );
            }

            $historyRows[] = new TargetHistoryRow(
                branchId: $bId,
                branchName: $branchName,
                isGroup: false,
                cells: $cells,
            );
        }

        // Build Group history row if canSeeGroup is true
        if ($canSeeGroup && count($branchIds) > 0) {
            $groupCells = [];
            foreach ($historyMonths as $mKey) {
                $groupTarget = 0.0;
                $groupActual = 0.0;
                $hasAnyTarget = false;

                foreach ($branchIds as $bId) {
                    $t = $targetMap[$bId][$mKey] ?? null;
                    if ($t !== null && $t > 0.0) {
                        $hasAnyTarget = true;
                        $groupTarget += $t;
                        $groupActual += ($actualMap[$bId][$mKey] ?? 0.0);
                    }
                }

                if ($hasAnyTarget && $groupTarget > 0.0) {
                    $achievement = $groupActual / $groupTarget;
                    $status = $this->determineStatus($achievement);
                } else {
                    $achievement = null;
                    $groupTarget = null;
                    $groupActual = null;
                    $status = 'no_target';
                }

                $groupCells[$mKey] = new TargetHistoryCell(
                    monthKey: $mKey,
                    target: $groupTarget !== null ? round($groupTarget, 2) : null,
                    actual: $groupActual !== null ? round($groupActual, 2) : null,
                    achievement: $achievement,
                    status: $status,
                );
            }

            $historyRows[] = new TargetHistoryRow(
                branchId: null,
                branchName: 'إجمالي المجموعة',
                isGroup: true,
                cells: $groupCells,
            );
        }

        return [$historyMonths, $historyMonthLabels, $historyRows];
    }

    /**
     * @param  list<int>  $branchIds
     * @return array<int, KpiValues>
     */
    private function fetchBranchActualTotals(array $branchIds, CarbonImmutable $today): array
    {
        $monthStart = $today->startOfMonth()->startOfDay();
        $todayDate = $today->startOfDay();

        $rows = DB::table('agg_branch_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$monthStart->toDateString(), $todayDate->toDateString()])
            ->groupBy('branch_id')
            ->selectRaw('
                branch_id,
                COUNT(DISTINCT business_date) as days_with_data,
                COALESCE(SUM(orders_count), 0) as orders_count,
                COALESCE(SUM(cancelled_count), 0) as cancelled_count,
                COALESCE(SUM(gross_sales), 0) as gross_sales,
                COALESCE(SUM(discounts), 0) as discounts,
                COALESCE(SUM(tax), 0) as tax,
                COALESCE(SUM(service), 0) as service,
                COALESCE(SUM(net_sales), 0) as net_sales,
                COALESCE(SUM(returns_amount), 0) as returns_amount,
                COALESCE(SUM(net_sales_after_returns), 0) as net_sales_after_returns,
                COALESCE(SUM(cogs), 0) as cogs,
                COALESCE(SUM(returned_cogs_recovered), 0) as returned_cogs_recovered,
                COALESCE(SUM(gross_profit), 0) as gross_profit,
                COALESCE(SUM(expenses_total), 0) as expenses_total,
                COALESCE(SUM(labor_cost), 0) as labor_cost,
                COALESCE(SUM(rent_cost), 0) as rent_cost,
                COALESCE(SUM(other_expenses), 0) as other_expenses,
                COALESCE(SUM(waste_cost), 0) as waste_cost,
                COALESCE(SUM(inventory_variance_cost), 0) as inventory_variance_cost,
                COALESCE(SUM(purchases_total), 0) as purchases_total,
                COALESCE(SUM(purchase_returns_total), 0) as purchase_returns_total,
                COALESCE(SUM(net_profit), 0) as net_profit,
                COALESCE(SUM(shifts_count), 0) as shifts_count,
                COALESCE(SUM(cash_deficit_total), 0) as cash_deficit_total
            ')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->branch_id] = KpiValues::fromRow($row);
        }

        return $result;
    }

    /**
     * @return list<float>
     */
    private function fetchSparkline(string $metric, CarbonImmutable $today, bool $canSeeGroup): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        $monthStart = $today->startOfMonth()->startOfDay();
        $metricCol = match ($metric) {
            'net_sales' => 'net_sales_after_returns',
            'net_profit' => 'net_profit',
            'orders' => 'orders_count',
            'food_cost_pct' => 'cogs',
            default => 'net_sales_after_returns',
        };

        $dailySums = DB::table('agg_branch_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [$monthStart->toDateString(), $today->toDateString()])
            ->select('business_date', DB::raw("SUM({$metricCol}) as total_val"))
            ->groupBy('business_date')
            ->orderBy('business_date')
            ->pluck('total_val', 'business_date')
            ->all();

        $sparkline = [];
        $cum = 0.0;
        $cursor = $monthStart;
        while ($cursor <= $today) {
            $d = $cursor->toDateString();
            $cum += (float) ($dailySums[$d] ?? 0.0);
            $sparkline[] = round($cum, 2);
            $cursor = $cursor->addDay();
        }

        return $sparkline;
    }

    private function resolveBranchName(?Branch $branch, int $branchId, int $index, bool $canSeeOtherBranchNames): string
    {
        if ($branch === null) {
            return "فرع #{$branchId}";
        }

        if (! $canSeeOtherBranchNames && count($this->query->branchIds()) > 1) {
            return 'فرع #'.($index + 1);
        }

        return $branch->name;
    }

    private function determineStatus(?float $achievementToDate): string
    {
        if ($achievementToDate === null) {
            return 'on_track';
        }

        if ($achievementToDate >= 1.0) {
            return 'on_track';
        }

        if ($achievementToDate >= 0.9) {
            return 'at_risk';
        }

        return 'behind';
    }
}
