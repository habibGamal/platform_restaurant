<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;

class DiscountIntensityWidget extends BaseReportViewWidget
{
    protected ?string $heading = 'كثافة الخصومات حسب الفرع (Discount Intensity — K03 ÷ K02)';

    protected function getColumns(): array
    {
        return [
            'branch' => 'الفرع',
            'gross_sales' => 'إجمالي المبيعات (K02)',
            'discounts' => 'الخصومات (K03)',
            'discount_pct' => 'نسبة الخصم',
            'comp_discount_pct' => 'نسبة المقارنة',
            'delta_pct' => 'التغير',
            'status' => 'مؤشر الكثافة',
        ];
    }

    protected function getRows(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = SalesComparisonReport::for($query);
        $rowsData = $report->data()->discountIntensity;

        $rows = [];
        foreach ($rowsData as $row) {
            $deltaHtml = '—';
            if ($row->deltaDiscountPct !== null) {
                $color = $row->deltaDiscountPct > 0 ? 'text-rose-600 dark:text-rose-400 font-semibold' : ($row->deltaDiscountPct < 0 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-gray-500');
                $sign = $row->deltaDiscountPct > 0 ? '+' : '';
                $deltaHtml = "<span class='{$color} dir-ltr'>{$sign}{$row->deltaDiscountPct}%</span>";
            }

            $pctVal = ($row->discountPct ?? 0.0) * 100;
            if ($pctVal > 15.0) {
                $statusHtml = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400">مرتفع جداً (>15%)</span>';
            } elseif ($pctVal > 8.0) {
                $statusHtml = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">متوسط (8-15%)</span>';
            } else {
                $statusHtml = '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">منضبط (≤8%)</span>';
            }

            $rows[] = [
                'branch' => '<span class="font-semibold text-gray-900 dark:text-white">'.e($row->branchName).'</span>',
                'gross_sales' => Format::money($row->grossSales),
                'discounts' => Format::money($row->discounts),
                'discount_pct' => '<span class="font-bold text-gray-900 dark:text-white">'.Format::percent($row->discountPct).'</span>',
                'comp_discount_pct' => Format::percent($row->compDiscountPct),
                'delta_pct' => $deltaHtml,
                'status' => $statusHtml,
            ];
        }

        return $rows;
    }
}
