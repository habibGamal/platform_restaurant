<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;

class SalesKpiMatrixWidget extends BaseReportViewWidget
{
    protected ?string $heading = 'مصفوفة مؤشرات الأداء للفروع (Side-by-Side Matrix)';

    protected function getColumns(): array
    {
        return [
            'branch' => 'الفرع',
            'sales' => 'صافي المبيعات (K06)',
            'orders' => 'عدد الطلبات (K01)',
            'avg_ticket' => 'متوسط الفاتورة (K07)',
            'discounts' => 'الخصومات (K03)',
            'cancel_rate' => 'نسبة الإلغاء (K21)',
            'return_rate' => 'نسبة المرتجع (K22)',
            'days' => 'أيام العمل',
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
        $matrix = $report->data()->matrix;

        $rows = [];
        foreach ($matrix as $row) {
            $branchHtml = $row->isGroupAverage
                ? '<div class="font-bold text-primary-600 dark:text-primary-400 flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-primary-500"></span>'.e($row->branchName).'</div>'
                : '<div class="font-semibold text-gray-900 dark:text-white">'.e($row->branchName).'</div>';

            $rows[] = [
                'branch' => $branchHtml,
                'sales' => $this->formatCell($row->sales, $row->salesGrowth, 'money', true),
                'orders' => $this->formatCell($row->orders !== null ? (float) $row->orders : null, $row->ordersGrowth, 'number', true),
                'avg_ticket' => $this->formatCell($row->avgTicket, $row->avgTicketGrowth, 'money', true),
                'discounts' => $this->formatCell($row->discounts, $row->discountsGrowth, 'money', false),
                'cancel_rate' => $this->formatCell($row->cancelRate, $row->cancelRateGrowth, 'percent', false),
                'return_rate' => $this->formatCell($row->returnRate, $row->returnRateGrowth, 'percent', false),
                'days' => '<span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-white/10 text-gray-700 dark:text-gray-300 font-medium">'.Format::number((float) $row->daysWithData).' يوم</span>',
            ];
        }

        return $rows;
    }

    private function formatCell(?float $val, ?float $growth, string $format = 'money', bool $higherIsBetter = true): string
    {
        $formattedVal = match ($format) {
            'money' => Format::money($val),
            'percent' => Format::percent($val),
            default => Format::number($val),
        };

        if ($growth === null) {
            return "<div>{$formattedVal}</div>";
        }

        $growthFormatted = Format::growth($growth);
        $rounded = round($growth * 100, 1);

        $colorClass = 'text-gray-400 dark:text-gray-500';
        if ($rounded > 0.0) {
            $colorClass = $higherIsBetter
                ? 'text-emerald-600 dark:text-emerald-400 font-semibold'
                : 'text-rose-600 dark:text-rose-400 font-semibold';
        } elseif ($rounded < 0.0) {
            $colorClass = $higherIsBetter
                ? 'text-rose-600 dark:text-rose-400 font-semibold'
                : 'text-emerald-600 dark:text-emerald-400 font-semibold';
        }

        return "<div>{$formattedVal} <span class='text-xs {$colorClass} inline-block ms-1 dir-ltr'>{$growthFormatted}</span></div>";
    }
}
