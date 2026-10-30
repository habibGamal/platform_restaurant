<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ShiftsWeekdays;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ShiftsWeekdaysReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;

class ShiftSlotsTableWidget extends BaseReportTableWidget
{
    use InteractsWithShiftsReportFilters;

    protected static ?string $heading = 'مقارنة فترات الورديات (صباحية ومسائية)';

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
            foreach ($data->shiftSlots as $row) {
                $records[$row->id] = [
                    'id' => $row->id,
                    'branch' => $row->branchName,
                    'slot' => $row->slotLabel,
                    'shifts_count' => Format::number($row->shiftsCount),
                    'total_sales' => Format::money($row->totalSales),
                    'avg_sales' => Format::money($row->avgSalesPerShift),
                    'sales_per_hour' => Format::money($row->salesPerHour),
                    'avg_ticket' => Format::money($row->avgTicket),
                    'cancelled_count' => Format::number($row->cancelledCount),
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

            TextColumn::make('slot')
                ->label('فترة الوردية')
                ->badge()
                ->color(fn (string $state): string => str_contains($state, 'صباحية') ? 'info' : 'warning'),

            TextColumn::make('shifts_count')
                ->label('عدد الورديات')
                ->alignEnd(),

            TextColumn::make('total_sales')
                ->label('إجمالي المبيعات (K06)')
                ->alignEnd()
                ->weight('bold'),

            TextColumn::make('avg_sales')
                ->label('متوسط مبيعات الوردية')
                ->alignEnd(),

            TextColumn::make('sales_per_hour')
                ->label('المبيعات / ساعة')
                ->alignEnd(),

            TextColumn::make('avg_ticket')
                ->label('متوسط الطلب (K07)')
                ->alignEnd(),

            TextColumn::make('cancelled_count')
                ->label('الملغاة')
                ->alignEnd(),
        ];
    }
}
