<?php

declare(strict_types=1);

namespace App\Ai\Tools\Operations;

use App\Ai\Tools\Finance\ReportTool;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final class PeakHoursTool extends ReportTool
{
    public function name(): string
    {
        return 'peak_hours';
    }

    public function description(): string
    {
        return 'Find the top 10 hours by average daily orders across selected branches using the shifts/weekday report. Calendar-event days are excluded from averages; hourly totals cover the full period.';
    }

    public function progressLabel(): string
    {
        return 'بحدد ساعات الذروة…';
    }

    protected function report(MetricsQuery $query): array
    {
        $peaks = ShiftsWeekdaysReport::for($query)->data()->peakHours;
        $hours = [];
        foreach ($peaks->labels as $index => $label) {
            $orders = 0.0;
            foreach ($peaks->datasets as $dataset) {
                $orders += $dataset['data'][$index] ?? 0.0;
            }
            $hours[] = ['hour' => $label, 'average_daily_orders' => round($orders, 2)];
        }
        usort($hours, fn (array $left, array $right): int => $right['average_daily_orders'] <=> $left['average_daily_orders']);

        return [
            'hours' => array_slice($hours, 0, 10),
            'hourly_totals' => $query->hourlyTotals($query->filters()->period, '23:59'),
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        if ($metrics['hours'] === []) {
            return 'مفيش بيانات ساعات كفاية لتحديد الذروة.';
        }
        $peak = $metrics['hours'][0];

        return "أعلى ساعة {$peak['hour']} بمتوسط {$peak['average_daily_orders']} طلب يوميًا في {$branchCount} فروع.";
    }
}
