<?php

declare(strict_types=1);

namespace App\Domain\Targets;

use App\Models\Branch;
use App\Models\CalendarEvent;
use App\Models\Target;
use App\Support\Weekday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class TargetDistributor
{
    /**
     * Compute the daily distribution of a target.
     *
     * @return array<string, float> array of date 'Y-m-d' => daily target value
     */
    public static function daily(Target $t): array
    {
        $targetMonth = CarbonImmutable::parse($t->period_month)->startOfMonth()->startOfDay();
        $targetMonthEnd = $targetMonth->endOfMonth()->startOfDay();

        $branch = Branch::withoutGlobalScopes()->find($t->branch_id);
        $openedOn = $branch?->opened_on !== null ? CarbonImmutable::parse($branch->opened_on)->startOfDay() : null;

        // 1. Gather all calendar days of the target month
        /** @var list<string> $allDates */
        $allDates = [];
        /** @var list<string> $eligibleDates */
        $eligibleDates = [];

        $curr = $targetMonth;
        while ($curr <= $targetMonthEnd) {
            $dateStr = $curr->toDateString();
            $allDates[] = $dateStr;

            if ($openedOn === null || $curr >= $openedOn) {
                $eligibleDates[] = $dateStr;
            }

            $curr = $curr->addDay();
        }

        $targetValue = (float) $t->value;

        if (empty($eligibleDates)) {
            $zeroMap = [];
            foreach ($allDates as $dateStr) {
                $zeroMap[$dateStr] = 0.0;
            }

            return $zeroMap;
        }

        // 2. Check if weekday_weights should be computed
        $useWeekdayWeights = ($t->distribution === 'weekday_weights');
        $weights = null;

        if ($useWeekdayWeights) {
            $weights = self::computeWeekdayWeights($t, $targetMonth);
        }

        // 3. If weekday weights were not applicable or fell back to flat
        if ($weights === null) {
            return self::distributeFlat($allDates, $eligibleDates, $targetValue);
        }

        // 4. Distribute using weekday weights
        return self::distributeByWeights($allDates, $eligibleDates, $targetValue, $weights);
    }

    /**
     * Persist daily distribution to target_daily table (delete + insert).
     */
    public static function persist(Target $t): void
    {
        $daily = self::daily($t);

        DB::transaction(function () use ($t, $daily): void {
            DB::table('target_daily')->where('target_id', $t->id)->delete();

            $insertData = [];
            foreach ($daily as $date => $val) {
                $insertData[] = [
                    'target_id' => $t->id,
                    'date' => $date,
                    'value' => $val,
                ];
            }

            if (! empty($insertData)) {
                DB::table('target_daily')->insert($insertData);
            }
        });
    }

    /**
     * Compute weights per weekday (0..6) from 8 weeks of history.
     * Returns null when falling back to flat (fewer than 4 weeks of history, 0 sales, etc.).
     *
     * @return array<int, float>|null
     */
    private static function computeWeekdayWeights(Target $t, CarbonImmutable $targetMonth): ?array
    {
        // Find the last Friday before the target month starts
        $lastFriday = $targetMonth->subDay();
        while (Weekday::of($lastFriday) !== 6) {
            $lastFriday = $lastFriday->subDay();
        }

        // 8 full weeks (56 days) = ending on $lastFriday, starting on $lastFriday - 55 days (a Saturday)
        $eightWeeksStart = $lastFriday->subDays(55);

        // Fetch calendar events to exclude
        $events = CalendarEvent::query()
            ->where(function ($q) use ($t): void {
                $q->where('organization_id', $t->organization_id)
                    ->orWhereNull('organization_id');
            })
            ->where('date_from', '<=', $lastFriday->toDateString())
            ->where('date_to', '>=', $eightWeeksStart->toDateString())
            ->get();

        $excludedDates = [];
        foreach ($events as $event) {
            $eStart = CarbonImmutable::parse($event->date_from)->startOfDay();
            $eEnd = CarbonImmutable::parse($event->date_to)->startOfDay();

            $cursor = $eStart > $eightWeeksStart ? $eStart : $eightWeeksStart;
            $limit = $eEnd < $lastFriday ? $eEnd : $lastFriday;

            while ($cursor <= $limit) {
                $excludedDates[$cursor->toDateString()] = true;
                $cursor = $cursor->addDay();
            }
        }

        // Query agg_branch_daily over the 8 weeks
        $rows = DB::table('agg_branch_daily')
            ->where('branch_id', $t->branch_id)
            ->whereBetween('business_date', [
                $eightWeeksStart->toDateString(),
                $lastFriday->toDateString(),
            ])
            ->select(['business_date', 'weekday', 'net_sales_after_returns'])
            ->get();

        $validRows = [];
        $weeksWithHistory = [];

        foreach ($rows as $row) {
            $dateStr = (string) $row->business_date;
            if (isset($excludedDates[$dateStr])) {
                continue;
            }

            $validRows[] = $row;
            $d = CarbonImmutable::parse($dateStr);
            $weekStart = $d->startOfWeek(CarbonInterface::SATURDAY)->toDateString();
            $weeksWithHistory[$weekStart] = true;
        }

        // Rule: fall back to flat when fewer than 4 weeks of history
        if (count($weeksWithHistory) < 4) {
            return null;
        }

        $salesByWeekday = array_fill(0, 7, 0.0);
        foreach ($validRows as $row) {
            $w = (int) $row->weekday;
            $salesByWeekday[$w] += (float) $row->net_sales_after_returns;
        }

        $totalSales = array_sum($salesByWeekday);
        if ($totalSales <= 0.0) {
            return null;
        }

        $weekdayWeights = [];
        for ($w = 0; $w < 7; $w++) {
            $weekdayWeights[$w] = $salesByWeekday[$w] / $totalSales;
        }

        return $weekdayWeights;
    }

    /**
     * @param  list<string>  $allDates
     * @param  list<string>  $eligibleDates
     * @return array<string, float>
     */
    private static function distributeFlat(array $allDates, array $eligibleDates, float $targetValue): array
    {
        $k = count($eligibleDates);
        $result = [];

        foreach ($allDates as $d) {
            $result[$d] = 0.0;
        }

        $allocated = 0.0;
        $base = round($targetValue / $k, 2);

        for ($i = 0; $i < $k - 1; $i++) {
            $d = $eligibleDates[$i];
            $result[$d] = $base;
            $allocated += $base;
        }

        $lastDate = $eligibleDates[$k - 1];
        $result[$lastDate] = round($targetValue - $allocated, 2);

        ksort($result);

        return $result;
    }

    /**
     * @param  list<string>  $allDates
     * @param  list<string>  $eligibleDates
     * @param  array<int, float>  $weekdayWeights
     * @return array<string, float>
     */
    private static function distributeByWeights(
        array $allDates,
        array $eligibleDates,
        float $targetValue,
        array $weekdayWeights,
    ): array {
        $dayWeights = [];
        foreach ($eligibleDates as $dateStr) {
            $d = CarbonImmutable::parse($dateStr);
            $w = Weekday::of($d);
            $dayWeights[$dateStr] = $weekdayWeights[$w] ?? 0.0;
        }

        $totalWeight = array_sum($dayWeights);
        if ($totalWeight <= 0.0) {
            return self::distributeFlat($allDates, $eligibleDates, $targetValue);
        }

        $result = [];
        foreach ($allDates as $d) {
            $result[$d] = 0.0;
        }

        $k = count($eligibleDates);
        $allocated = 0.0;

        for ($i = 0; $i < $k - 1; $i++) {
            $dateStr = $eligibleDates[$i];
            $val = round($targetValue * ($dayWeights[$dateStr] / $totalWeight), 2);
            $result[$dateStr] = $val;
            $allocated += $val;
        }

        $lastDate = $eligibleDates[$k - 1];
        $result[$lastDate] = round($targetValue - $allocated, 2);

        ksort($result);

        return $result;
    }
}
