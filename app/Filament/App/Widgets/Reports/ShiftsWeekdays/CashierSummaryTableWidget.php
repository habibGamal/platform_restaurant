<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;

class CashierSummaryTableWidget extends BaseReportTableWidget
{
    use InteractsWithShiftsReportFilters;

    protected static ?string $heading = 'ملخص أداء الكاشير والعجز النقدي';

    protected function getReportRecords(): ?Closure
    {
        return function (): array {
            $user = auth()->user();
            if (! $user) {
                return [];
            }

            $filters = $this->reportFilters();
            $query = MetricsQuery::for($user, $filters);
            $excludeEvents = (bool) ($this->pageFilters['exclude_events'] ?? true);
            $data = ShiftsWeekdaysReport::for($query, $excludeEvents)->data();

            $records = [];
            foreach ($data->cashierSummary as $row) {
                $records[$row->id] = [
                    'id' => $row->id,
                    'cashier' => $row->cashierName,
                    'branch' => $row->branchName,
                    'shifts_count' => Format::number($row->shiftsCount),
                    'total_sales' => Format::money($row->totalSales),
                    'sales_per_hour' => Format::money($row->salesPerHour),
                    'avg_ticket' => Format::money($row->avgTicket),
                    'cancelled_count' => Format::number($row->cancelledCount),
                    'cancel_rate' => Format::percent($row->cancelRate),
                    'deficit_count' => Format::number($row->deficitCount),
                    'deficit_total' => Format::money($row->deficitTotal),
                    'has_deficit' => $row->deficitTotal > 0.0,
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('cashier')
                ->label('الكاشير')
                ->weight('medium')
                ->searchable(),

            TextColumn::make('branch')
                ->label('الفرع')
                ->sortable(),

            TextColumn::make('shifts_count')
                ->label('عدد الورديات')
                ->alignEnd(),

            TextColumn::make('total_sales')
                ->label('إجمالي المبيعات (K06)')
                ->alignEnd()
                ->weight('bold'),

            TextColumn::make('sales_per_hour')
                ->label('المبيعات / ساعة')
                ->alignEnd(),

            TextColumn::make('avg_ticket')
                ->label('متوسط الطلب (K07)')
                ->alignEnd(),

            TextColumn::make('cancelled_count')
                ->label('الملغاة')
                ->alignEnd(),

            TextColumn::make('cancel_rate')
                ->label('نسبة الإلغاء (K21)')
                ->alignEnd(),

            TextColumn::make('deficit_count')
                ->label('مرات العجز')
                ->alignEnd()
                ->badge()
                ->color(fn (array $record): string => ($record['has_deficit'] ?? false) ? 'danger' : 'gray'),

            TextColumn::make('deficit_total')
                ->label('إجمالي العجز (K25)')
                ->alignEnd()
                ->weight('bold')
                ->color(fn (array $record): string => ($record['has_deficit'] ?? false) ? 'danger' : 'gray'),
        ];
    }
}
