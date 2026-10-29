<?php

declare(strict_types=1);

namespace App\Domain\Targets;

use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\MetricsQuery;
use App\Models\Target;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class TargetAchievement
{
    /**
     * Compute target achievement for all branches in the query.
     * "To date" = from the 1st of today's month to today inclusive.
     *
     * @return list<BranchTargetStatus>
     */
    public static function for(MetricsQuery $q, string $metric, CarbonImmutable $today): array
    {
        $branchIds = $q->branchIds();
        $results = [];

        $monthStart = $today->startOfMonth()->startOfDay();
        $monthEnd = $today->endOfMonth()->startOfDay();
        $todayDate = $today->startOfDay();

        $elapsedDays = $today->day;
        $remainingDays = max(0, (int) $todayDate->diffInDays($monthEnd));

        // Load targets for the selected branches and month
        $targets = Target::query()
            ->whereIn('branch_id', $branchIds)
            ->where('metric', $metric)
            ->where('period_month', $monthStart->toDateString())
            ->get()
            ->keyBy('branch_id');

        // Query actuals from agg_branch_daily for each branch from month start to today
        $actualRows = [];
        if (! empty($branchIds)) {
            $rows = DB::table('agg_branch_daily')
                ->whereIn('branch_id', $branchIds)
                ->whereBetween('business_date', [
                    $monthStart->toDateString(),
                    $todayDate->toDateString(),
                ])
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

            foreach ($rows as $row) {
                $actualRows[(int) $row->branch_id] = KpiValues::fromRow($row);
            }
        }

        foreach ($branchIds as $branchId) {
            $kpi = $actualRows[$branchId] ?? KpiValues::zero();
            $actualToDate = self::extractActualMetric($metric, $kpi);
            $currentDailyAverage = $elapsedDays > 0 ? ($actualToDate / $elapsedDays) : null;

            /** @var Target|null $target */
            $target = $targets->get($branchId);

            if ($target === null) {
                $results[] = new BranchTargetStatus(
                    branchId: $branchId,
                    metric: $metric,
                    targetMonth: null,
                    targetToDate: null,
                    actualToDate: $actualToDate,
                    achievementToDate: null,
                    monthAchievement: null,
                    gap: null,
                    projected: null,
                    projectedAchievement: null,
                    requiredDailyAverage: null,
                    currentDailyAverage: $currentDailyAverage,
                    status: 'no_target',
                );

                continue;
            }

            $targetMonth = (float) $target->value;

            // Special handling for food_cost_pct ceiling
            if ($metric === 'food_cost_pct') {
                $targetToDate = $targetMonth;
                $achievementToDate = ($targetToDate != 0.0) ? ($actualToDate / $targetToDate) : null;
                $monthAchievement = ($targetMonth != 0.0) ? ($actualToDate / $targetMonth) : null;
                $gap = $targetToDate - $actualToDate;
                $projected = $actualToDate;
                $projectedAchievement = $monthAchievement;
                $status = ($actualToDate <= $targetMonth) ? 'within' : 'above';

                $results[] = new BranchTargetStatus(
                    branchId: $branchId,
                    metric: $metric,
                    targetMonth: $targetMonth,
                    targetToDate: $targetToDate,
                    actualToDate: $actualToDate,
                    achievementToDate: $achievementToDate,
                    monthAchievement: $monthAchievement,
                    gap: $gap,
                    projected: $projected,
                    projectedAchievement: $projectedAchievement,
                    requiredDailyAverage: null,
                    currentDailyAverage: $actualToDate,
                    status: $status,
                );

                continue;
            }

            // Normal cumulative metrics: net_sales, net_profit, orders
            // Fetch daily target distribution
            $dailyMap = DB::table('target_daily')
                ->where('target_id', $target->id)
                ->pluck('value', 'date')
                ->map(fn ($v): float => (float) $v)
                ->all();

            if (empty($dailyMap)) {
                $dailyMap = TargetDistributor::daily($target);
            }

            $targetToDate = 0.0;
            $remainingTargetSum = 0.0;
            $todayStr = $todayDate->toDateString();

            foreach ($dailyMap as $dateStr => $val) {
                if ($dateStr <= $todayStr) {
                    $targetToDate += $val;
                } else {
                    $remainingTargetSum += $val;
                }
            }

            $achievementToDate = ($targetToDate != 0.0) ? ($actualToDate / $targetToDate) : null;
            $monthAchievement = ($targetMonth != 0.0) ? ($actualToDate / $targetMonth) : null;
            $gap = $targetToDate - $actualToDate;

            $paceFactor = ($targetToDate != 0.0) ? ($actualToDate / $targetToDate) : 1.0;
            $projected = $actualToDate + ($remainingTargetSum * $paceFactor);
            $projectedAchievement = ($targetMonth != 0.0) ? ($projected / $targetMonth) : null;

            $requiredDailyAverage = ($remainingDays > 0) ? (($targetMonth - $actualToDate) / $remainingDays) : null;

            $status = self::determineStatus($achievementToDate);

            $results[] = new BranchTargetStatus(
                branchId: $branchId,
                metric: $metric,
                targetMonth: $targetMonth,
                targetToDate: $targetToDate,
                actualToDate: $actualToDate,
                achievementToDate: $achievementToDate,
                monthAchievement: $monthAchievement,
                gap: $gap,
                projected: $projected,
                projectedAchievement: $projectedAchievement,
                requiredDailyAverage: $requiredDailyAverage,
                currentDailyAverage: $currentDailyAverage,
                status: $status,
            );
        }

        return $results;
    }

    private static function extractActualMetric(string $metric, KpiValues $kpi): float
    {
        return match ($metric) {
            'net_sales' => $kpi->net_sales_after_returns,
            'net_profit' => $kpi->net_profit,
            'orders' => (float) $kpi->orders_count,
            'food_cost_pct' => (float) ($kpi->foodCostPct() ?? 0.0),
            default => 0.0,
        };
    }

    private static function determineStatus(?float $achievementToDate): string
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
