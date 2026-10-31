<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;
use Filament\Tables\Table;

class SalesKpiMatrixWidget extends BaseReportViewWidget
{
    protected ?string $heading = 'مصفوفة مؤشرات الأداء للفروع (Side-by-Side Matrix)';

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->recordClasses(fn (array $record): ?string => ($record['_is_group'] ?? false) ? 'bg-primary-50/50 dark:bg-primary-950/20 font-semibold' : null);
    }

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
            $recordKey = $row->isGroupAverage ? 'group_avg' : 'branch_'.($row->branchId ?? '0');

            $branchHtml = $row->isGroupAverage
                ? '<div class="font-bold text-primary-600 dark:text-primary-400 flex items-center gap-1.5 whitespace-nowrap"><span class="inline-block w-2 h-2 rounded-full bg-primary-500 shrink-0"></span>'.e($row->branchName).'</div>'
                : '<div class="font-semibold text-gray-900 dark:text-white whitespace-nowrap">'.e($row->branchName).'</div>';

            $rows[$recordKey] = [
                '_is_group' => $row->isGroupAverage,
                'branch' => $branchHtml,
                'sales' => $this->formatCell($row->sales, $row->salesGrowth, 'money', true),
                'orders' => $this->formatCell($row->orders !== null ? (float) $row->orders : null, $row->ordersGrowth, 'number', true),
                'avg_ticket' => $this->formatCell($row->avgTicket, $row->avgTicketGrowth, 'money', true),
                'discounts' => $this->formatCell($row->discounts, $row->discountsGrowth, 'money', false),
                'cancel_rate' => $this->formatCell($row->cancelRate, $row->cancelRateGrowth, 'percent', false),
                'return_rate' => $this->formatCell($row->returnRate, $row->returnRateGrowth, 'percent', false),
                'days' => '<span class="inline-block text-xs px-2.5 py-1 rounded-full bg-gray-100 dark:bg-white/10 text-gray-700 dark:text-gray-300 font-medium whitespace-nowrap">'.Format::number((float) $row->daysWithData).' يوم</span>',
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
            return "<div class=\"font-medium text-gray-950 dark:text-white whitespace-nowrap\">{$formattedVal}</div>";
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

        return "<div class=\"space-y-0.5 whitespace-nowrap\">
            <div class=\"font-medium text-gray-950 dark:text-white\">{$formattedVal}</div>
            <div class=\"text-xs {$colorClass}\"><span class=\"inline-block dir-ltr font-semibold\">{$growthFormatted}</span></div>
        </div>";
    }
}
