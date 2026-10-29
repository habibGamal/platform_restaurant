<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports;

use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use App\Support\BranchColor;
use Filament\Widgets\ChartWidget;

abstract class BaseReportChartWidget extends ChartWidget
{
    use InteractsWithReportFilters;

    protected static bool $isLazy = true;

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
