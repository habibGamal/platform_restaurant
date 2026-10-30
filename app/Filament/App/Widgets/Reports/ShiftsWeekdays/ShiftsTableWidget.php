<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;

class ShiftsTableWidget extends BaseReportTableWidget
{
    use InteractsWithShiftsReportFilters;

    protected static ?string $heading = 'سجل الورديات التفصيلي';

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
            foreach ($data->shifts as $row) {
                $records[$row->id] = [
                    'id' => $row->id,
                    'branch' => $row->branchName,
                    'business_date' => $row->businessDate,
                    'weekday' => $row->weekdayLabel,
                    'time_range' => $row->timeRange,
                    'duration' => $row->durationFormatted,
                    'cashier' => $row->cashierName,
                    'orders_count' => Format::number($row->ordersCount),
                    'net_sales' => Format::money($row->netSales),
                    'sales_per_hour' => Format::money($row->salesPerHour),
                    'avg_ticket' => Format::money($row->avgTicket),
                    'cancelled_count' => Format::number($row->cancelledCount),
                    'cash_deficit' => Format::money($row->cashDeficit),
                    'has_deficit' => $row->hasDeficit,
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('branch')
                ->label('الفرع')
                ->sortable(),

            TextColumn::make('business_date')
                ->label('تاريخ العمل')
                ->sortable(),

            TextColumn::make('weekday')
                ->label('اليوم'),

            TextColumn::make('time_range')
                ->label('فترة الوردية')
                ->alignCenter(),

            TextColumn::make('duration')
                ->label('المدة')
                ->alignCenter(),

            TextColumn::make('cashier')
                ->label('الكاشير')
                ->searchable(),

            TextColumn::make('orders_count')
                ->label('الطلبات (K01)')
                ->alignEnd(),

            TextColumn::make('net_sales')
                ->label('المبيعات (K06)')
                ->alignEnd()
                ->weight('bold'),

            TextColumn::make('sales_per_hour')
                ->label('مبيعات / ساعة')
                ->alignEnd(),

            TextColumn::make('avg_ticket')
                ->label('متوسط الطلب (K07)')
                ->alignEnd(),

            TextColumn::make('cancelled_count')
                ->label('الملغاة')
                ->alignEnd(),

            TextColumn::make('cash_deficit')
                ->label('عجز النقدية (K25)')
                ->alignEnd()
                ->badge(fn (array $record): bool => (bool) ($record['has_deficit'] ?? false))
                ->color(fn (array $record): string => ($record['has_deficit'] ?? false) ? 'danger' : 'gray'),
        ];
    }
}
