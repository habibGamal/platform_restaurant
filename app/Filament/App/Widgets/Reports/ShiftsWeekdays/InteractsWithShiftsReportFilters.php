<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\Period\PeriodPreset;
use App\Domain\Metrics\Period\ReportFilters;
use Carbon\CarbonImmutable;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

trait InteractsWithShiftsReportFilters
{
    use InteractsWithPageFilters;

    public function reportFilters(): ReportFilters
    {
        $rawFilters = $this->pageFilters ?? [];

        // Spec §Filters: Period (default Last 8 weeks — weekday averages need several weeks)
        if (! isset($rawFilters['preset']) || $rawFilters['preset'] === '') {
            $rawFilters['preset'] = PeriodPreset::last_8_weeks->value;
        }

        if (isset($rawFilters['from']) && is_string($rawFilters['from'])) {
            $rawFilters['from'] = substr($rawFilters['from'], 0, 10);
        }

        if (isset($rawFilters['to']) && is_string($rawFilters['to'])) {
            $rawFilters['to'] = substr($rawFilters['to'], 0, 10);
        }

        return ReportFilters::fromArray(
            $rawFilters,
            CarbonImmutable::now('Africa/Cairo')->startOfDay(),
            now('Africa/Cairo')->format('H:i')
        );
    }
}
