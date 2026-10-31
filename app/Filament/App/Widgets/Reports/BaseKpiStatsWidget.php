<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget as BaseStatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

abstract class BaseKpiStatsWidget extends BaseStatsOverviewWidget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = false;

    protected int|array|null $columns = [
        'default' => 1,
        'sm' => 2,
        'md' => 3,
        'xl' => 4,
    ];

    /**
     * @param  array<float|int>  $sparkline
     */
    protected function kpiStat(
        string $label,
        ?float $value,
        ?float $comparison,
        string $format = 'money',
        bool $higherIsBetter = true,
        array $sparkline = [],
    ): Stat {
        $formattedValue = match ($format) {
            'money' => Format::money($value),
            'percent' => Format::percent($value),
            default => Format::number($value),
        };

        $stat = Stat::make($label, $formattedValue);

        if ($value !== null && $comparison !== null && $comparison != 0.0) {
            $growth = ($value - $comparison) / abs($comparison);
            $growthFormatted = Format::growth($growth);
            $compFormatted = match ($format) {
                'money' => Format::money($comparison),
                'percent' => Format::percent($comparison),
                default => Format::number($comparison),
            };

            $stat->description("{$growthFormatted} مقابل {$compFormatted}");

            $roundedPercent = round($growth * 100, 1);
            if ($roundedPercent > 0.0) {
                $color = $higherIsBetter ? 'success' : 'danger';
            } elseif ($roundedPercent < 0.0) {
                $color = $higherIsBetter ? 'danger' : 'success';
            } else {
                $color = 'gray';
            }

            $stat->descriptionColor($color);
            $stat->color($color);
        } else {
            $stat->description('—');
        }

        if (! empty($sparkline)) {
            $stat->chart($sparkline);
        }

        return $stat;
    }
}
