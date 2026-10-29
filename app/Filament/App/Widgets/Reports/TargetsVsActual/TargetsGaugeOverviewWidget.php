<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\TargetsVsActual;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\TargetsVsActualReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TargetsGaugeOverviewWidget extends BaseKpiStatsWidget
{
    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = TargetsVsActualReport::for($query);
        $overview = $report->data()->overview;

        $stats = [];

        foreach ($overview as $metric => $metricData) {
            if ($metric === 'food_cost_pct') {
                $valueFormatted = Format::percent($metricData->actualToDate);

                if ($metricData->status === 'no_target') {
                    $stat = Stat::make($metricData->label, $valueFormatted)
                        ->description('لم يُحدد سقف مستهدف')
                        ->descriptionColor('gray')
                        ->color('gray');
                } else {
                    $ceilingFormatted = Format::percent($metricData->targetMonth);
                    $isWithin = ($metricData->status === 'within');
                    $desc = $isWithin
                        ? "✓ ضمن السقف المسموح ({$ceilingFormatted})"
                        : "✕ تجاوز السقف المسموح ({$ceilingFormatted})";
                    $color = $isWithin ? 'success' : 'danger';

                    if ($metricData->note !== null) {
                        $desc .= " · {$metricData->note}";
                    }

                    $stat = Stat::make($metricData->label, $valueFormatted)
                        ->description($desc)
                        ->descriptionColor($color)
                        ->color($color);
                }
            } else {
                $valueFormatted = $metric === 'orders'
                    ? Format::number($metricData->actualToDate)
                    : Format::money($metricData->actualToDate);

                if ($metricData->status === 'no_target') {
                    $stat = Stat::make($metricData->label, $valueFormatted)
                        ->description('لم يُحدد مستهدف')
                        ->descriptionColor('gray')
                        ->color('gray');
                } else {
                    $targetToDateFormatted = $metric === 'orders'
                        ? Format::number($metricData->targetToDate)
                        : Format::money($metricData->targetToDate);

                    $projectedFormatted = $metric === 'orders'
                        ? Format::number($metricData->projected)
                        : Format::money($metricData->projected);

                    $achievementFormatted = Format::percent($metricData->achievementToDate);
                    $monthAchFormatted = Format::percent($metricData->monthAchievement);

                    $color = match ($metricData->status) {
                        'on_track' => 'success',
                        'at_risk' => 'warning',
                        'behind' => 'danger',
                        default => 'gray',
                    };

                    $desc = "تحقيق {$achievementFormatted} من المستهدف ({$targetToDateFormatted}) · محقق شهري {$monthAchFormatted} · متوقع {$projectedFormatted}";
                    if ($metricData->note !== null) {
                        $desc .= " · {$metricData->note}";
                    }

                    $stat = Stat::make($metricData->label, $valueFormatted)
                        ->description($desc)
                        ->descriptionColor($color)
                        ->color($color);
                }
            }

            if (! empty($metricData->sparkline)) {
                $stat->chart($metricData->sparkline);
            }

            $stats[] = $stat;
        }

        return $stats;
    }
}
