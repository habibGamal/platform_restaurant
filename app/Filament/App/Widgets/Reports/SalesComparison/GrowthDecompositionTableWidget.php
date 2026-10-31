<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\SalesComparisonReport;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;

class GrowthDecompositionTableWidget extends BaseReportViewWidget
{
    protected ?string $heading = 'تفكيك نمو المبيعات (أثر الطلبات مقابل أثر الفاتورة)';

    protected function getColumns(): array
    {
        return [
            'branch' => 'الفرع',
            'net_sales_0' => 'مبيعات سابقة (K04)',
            'net_sales_1' => 'مبيعات حالية (K04)',
            'delta_net_sales' => 'تغير المبيعات (ΔK04)',
            'orders_effect' => 'أثر الطلبات',
            'ticket_effect' => 'أثر الفاتورة',
            'returns_effect' => 'أثر المرتجعات (K05)',
            'delta_sales' => 'صافي التغير (ΔK06)',
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
        $decomposition = $report->data()->growthDecomposition;

        if (empty($decomposition)) {
            return [];
        }

        $rows = [];
        foreach ($decomposition as $row) {
            $deltaNetSalesClass = $row->deltaNetSales >= 0 ? 'text-emerald-600 dark:text-emerald-400 font-semibold' : 'text-rose-600 dark:text-rose-400 font-semibold';
            $ordersEffectClass = $row->ordersEffect >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
            $ticketEffectClass = $row->ticketEffect >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
            $returnsEffectClass = $row->returnsEffect >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400';
            $deltaSalesClass = $row->deltaSales >= 0 ? 'text-emerald-600 dark:text-emerald-400 font-bold' : 'text-rose-600 dark:text-rose-400 font-bold';

            $rows['branch_'.$row->branchId] = [
                'branch' => '<span class="font-semibold text-gray-900 dark:text-white whitespace-nowrap">'.e($row->branchName).'</span>',
                'net_sales_0' => '<span class="font-medium whitespace-nowrap">'.Format::money($row->netSales0).'</span>',
                'net_sales_1' => '<span class="font-medium whitespace-nowrap">'.Format::money($row->netSales1).'</span>',
                'delta_net_sales' => "<span class='{$deltaNetSalesClass} whitespace-nowrap'><span class='inline-block dir-ltr'>".($row->deltaNetSales >= 0 ? '+' : '').Format::money($row->deltaNetSales).'</span></span>',
                'orders_effect' => "<div class='text-xs font-medium {$ordersEffectClass} whitespace-nowrap'><span class='inline-block dir-ltr'>".($row->ordersEffect >= 0 ? '+' : '').Format::money($row->ordersEffect)."</span><div class='text-gray-400 font-normal mt-0.5'>({$row->orders1} مقابل {$row->orders0} طلب)</div></div>",
                'ticket_effect' => "<div class='text-xs font-medium {$ticketEffectClass} whitespace-nowrap'><span class='inline-block dir-ltr'>".($row->ticketEffect >= 0 ? '+' : '').Format::money($row->ticketEffect)."</span><div class='text-gray-400 font-normal mt-0.5'>(".Format::money($row->ticket1).' مقابل '.Format::money($row->ticket0).')</div></div>',
                'returns_effect' => "<div class='text-xs {$returnsEffectClass} whitespace-nowrap'><span class='inline-block dir-ltr'>".($row->returnsEffect >= 0 ? '+' : '').Format::money($row->returnsEffect)."</span><div class='text-gray-400 font-normal mt-0.5'>(مرتجعات: ".Format::money($row->returns1).')</div></div>',
                'delta_sales' => "<span class='{$deltaSalesClass} whitespace-nowrap'><span class='inline-block dir-ltr'>".($row->deltaSales >= 0 ? '+' : '').Format::money($row->deltaSales).'</span></span>',
            ];
        }

        return $rows;
    }
}
