<?php

declare(strict_types=1);

namespace App\Ai\Tools\Operations;

use App\Ai\Tools\Finance\ReportTool;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final class ShiftsWeekdaysTool extends ReportTool
{
    public function name(): string
    {
        return 'shifts_weekdays';
    }

    public function description(): string
    {
        return 'Review closed shifts, weekday patterns, shift slots and cashier summaries. Calendar-event days are excluded by the report.';
    }

    public function progressLabel(): string
    {
        return 'براجع الورديات وأيام الأسبوع…';
    }

    protected function report(MetricsQuery $query): array
    {
        $data = ShiftsWeekdaysReport::for($query)->data();

        return [
            'overview' => $data->overview, 'weekdays' => $data->weekdayProfile,
            'shifts' => $data->shifts, 'shift_slots' => $data->shiftSlots, 'cashiers' => $data->cashierSummary,
        ];
    }

    protected function summarize(array $metrics, int $branchCount): string
    {
        $shifts = count($metrics['shifts']);

        return "راجعت {$shifts} ورديات مغلقة ونمط أيام الأسبوع في {$branchCount} فروع.";
    }
}
