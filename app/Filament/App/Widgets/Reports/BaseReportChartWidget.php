<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use App\Support\BranchColor;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

abstract class BaseReportChartWidget extends ChartWidget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = true;

    protected ?string $maxHeight = '280px';

    /** @param array<string, mixed> $options */
    protected function percentageOptions(array $options = [], string $axis = 'y', bool $scatter = false): RawJs
    {
        $json = json_encode($options, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $axisJson = json_encode($axis, JSON_THROW_ON_ERROR);
        $format = $scatter
            ? "(context.dataset.label || '') + ': ' + context.parsed.x + ', ' + context.parsed.y + '%'"
            : "(context.dataset.label || '') + ': ' + context.formattedValue + '%'";

        $javascript = "(() => { const options = {$json}; options.scales ??= {}; options.scales[{$axisJson}] ??= {}; options.scales[{$axisJson}].ticks ??= {}; options.scales[{$axisJson}].ticks.callback = value => value + '%'; options.plugins ??= {}; options.plugins.tooltip ??= {}; options.plugins.tooltip.callbacks ??= {}; options.plugins.tooltip.callbacks.label = context => {$format}; return options; })()";

        // Filament inserts RawJs directly into the chart's double-quoted x-data attribute.
        return RawJs::make(e($javascript));
    }

    /**
     * @param  array<float|int>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function branchLineDataset(int $branchId, string $label, array $data, array $extra = []): array
    {
        $color = BranchColor::for($branchId);

        return array_merge([
            'label' => $label,
            'data' => $data,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'fill' => false,
            'tension' => 0.1,
        ], $extra);
    }

    /**
     * @param  array<float|int>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function branchBarDataset(int $branchId, string $label, array $data, array $extra = []): array
    {
        $color = BranchColor::for($branchId);

        return array_merge([
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $color,
            'borderColor' => $color,
            'borderWidth' => 1,
        ], $extra);
    }

    /**
     * @param  array<float|int>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function lineDataset(string $label, array $data, ?string $color = null, array $extra = []): array
    {
        $color ??= '#10b981';

        return array_merge([
            'label' => $label,
            'data' => $data,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'fill' => false,
            'tension' => 0.1,
        ], $extra);
    }

    /**
     * @param  array<float|int>  $data
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function barDataset(string $label, array $data, ?string $color = null, array $extra = []): array
    {
        $color ??= '#10b981';

        return array_merge([
            'label' => $label,
            'data' => $data,
            'backgroundColor' => $color,
            'borderColor' => $color,
            'borderWidth' => 1,
        ], $extra);
    }
}
