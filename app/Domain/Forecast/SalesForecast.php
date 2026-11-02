<?php

declare(strict_types=1);

namespace App\Domain\Forecast;

use App\Domain\Metrics\Completeness;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SalesForecast
{
    /** @return array<string, mixed> */
    public function forecast(MetricsQuery $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($query->filters()->channel !== null) {
            throw new InvalidArgumentException('Sales forecasts do not support channel filters.');
        }
        if ($query->filters()->likeForLike) {
            throw new InvalidArgumentException('Sales forecasts do not support like-for-like filters.');
        }
        $target = new Period($from, $to);
        $history = $this->history($query, $target->from);
        $result = [
            'status' => 'insufficient_data', 'method' => 'weekday_median_trend',
            'expected' => 0.0, 'low' => 0.0, 'high' => 0.0, 'by_week' => [],
            'complete_days_used' => count($history),
            'orders_considered' => array_sum(array_column($history, 'orders')),
            'history_from' => $history[0]['date'] ?? null,
            'history_to' => $history[count($history) - 1]['date'] ?? null,
            'history_cutoff' => $this->historyCutoff($query, $target->from)->toDateString(),
            'confidence' => 'low',
        ];
        if (count($history) < 28) {
            return $result + ['reason' => 'التوقع محتاج 28 يوم مكتمل على الأقل لكل الفروع المحددة.'];
        }
        if ($result['orders_considered'] < 200) {
            return $result + ['reason' => 'التوقع محتاج 200 طلب مكتمل على الأقل في الأيام المكتملة للفروع المحددة.'];
        }
        $byWeekday = [];
        foreach ($history as $row) {
            $byWeekday[CarbonImmutable::parse($row['date'])->dayOfWeek][] = $row['sales'];
        }
        $baseline = [];
        foreach ($byWeekday as $weekday => $sales) {
            $baseline[$weekday] = $this->median(array_slice($sales, -4));
        }
        foreach ($target->dates() as $date) {
            if (! isset($baseline[$date->dayOfWeek])) {
                return $result + ['reason' => 'مفيش تاريخ مكتمل كفاية لبعض أيام الأسبوع المطلوبة.'];
            }
        }

        $sales = array_column($history, 'sales');
        $previous = array_sum(array_slice($sales, 0, max(0, count($sales) - 28)));
        $recent = array_sum(array_slice($sales, -28));
        // Equal 28-day windows are required to infer growth; shorter histories use a neutral trend.
        $trend = count($history) === 56 && $previous > 0 ? max(0.85, min(1.15, $recent / $previous)) : 1.0;
        $deviations = [];
        foreach ($history as $row) {
            $base = $baseline[CarbonImmutable::parse($row['date'])->dayOfWeek];
            if ($base > 0) {
                $deviations[] = abs($row['sales'] / $base - 1);
            }
        }
        $spread = max($this->median($deviations), 0.05);
        $weeks = [];
        $expected = 0.0;
        foreach ($target->dates() as $date) {
            $daily = $baseline[$date->dayOfWeek] * $trend;
            $expected += $daily;
            $week = $date->startOfWeek()->toDateString();
            $weeks[$week] = ($weeks[$week] ?? 0.0) + $daily;
        }
        $byWeek = [];
        foreach ($weeks as $week => $value) {
            $byWeek[] = ['week_start' => $week, 'expected' => round($value, 2)];
        }

        return array_replace($result, [
            'status' => 'ok', 'expected' => round($expected, 2),
            'low' => round($expected * (1 - $spread), 2), 'high' => round($expected * (1 + $spread), 2),
            'by_week' => $byWeek, 'trend' => round($trend, 6), 'spread' => round($spread, 6),
            'confidence' => count($history) === 56 ? 'medium' : 'low',
            'trend_note' => count($history) === 56 ? 'مقارنة نافذتين من 28 يوم مكتمل.' : 'اتجاه محايد لعدم توفر نافذتين من 28 يوم مكتمل.',
        ]);
    }

    /** @return list<array{date: string, sales: float, orders: int}> */
    private function history(MetricsQuery $query, CarbonImmutable $targetFrom): array
    {
        $branchIds = $query->branchIds();
        if ($branchIds === []) {
            return [];
        }
        $end = $this->historyCutoff($query, $targetFrom);
        $earliest = $query->scoped('agg_branch_daily', new Period(CarbonImmutable::parse('1970-01-01'), $end))->min('business_date');
        if ($earliest === null) {
            return [];
        }
        $first = CarbonImmutable::parse((string) $earliest);
        $history = [];
        // Scan bounded windows so gaps can be backfilled without loading years of daily rows.
        while ($end >= $first && count($history) < 56) {
            $start = $end->subDays(55);
            $period = new Period($start < $first ? $first : $start, $end);
            $completeness = Completeness::for($query, $period);
            $daily = $query->scoped('agg_branch_daily', $period)
                ->selectRaw('business_date, SUM(net_sales_after_returns) AS sales, SUM(orders_count) AS orders')
                ->groupBy('business_date')->get()->keyBy('business_date');
            foreach (array_reverse($period->dates()) as $date) {
                foreach ($branchIds as $id) {
                    if ($completeness->statusFor($id, $date->toDateString()) !== 'complete') {
                        continue 2;
                    }
                }
                $row = $daily->get($date->toDateString());
                $history[] = ['date' => $date->toDateString(), 'sales' => (float) ($row->sales ?? 0), 'orders' => (int) ($row->orders ?? 0)];
                if (count($history) === 56) {
                    break;
                }
            }
            $end = $period->from->subDay();
        }

        return array_reverse($history);
    }

    private function historyCutoff(MetricsQuery $query, CarbonImmutable $targetFrom): CarbonImmutable
    {
        $timezone = $query->user()->organization->timezone ?? 'Africa/Cairo';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $lastBeforeTarget = $targetFrom->subDay();

        return $lastBeforeTarget < $today ? $lastBeforeTarget : $today;
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }
}
