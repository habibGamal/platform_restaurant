<?php

declare(strict_types=1);

namespace App\Filament\App\Pages\Reports;

use App\Domain\Metrics\Period\PeriodPreset;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\CashierSummaryTableWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\HourlyHeatmapWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\PeakHoursChartWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\ShiftsKpiOverviewWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\ShiftSlotsTableWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\ShiftsTableWidget;
use App\Filament\App\Widgets\Reports\ShiftsWeekdays\WeekdayProfileChartWidget;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ShiftsWeekdays extends ReportPage
{
    protected static string $routePath = 'reports/shifts';

    protected static ?string $title = 'الورديات وأيام الأسبوع';

    protected static ?string $navigationLabel = 'الورديات وأيام الأسبوع';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public function mount(): void
    {
        parent::mount();

        if (! isset($this->filters['preset'])) {
            $this->filters['preset'] = PeriodPreset::last_8_weeks->value;
        }

        if (! isset($this->filters['exclude_events'])) {
            $this->filters['exclude_events'] = true;
        }

        if (request()->has('exclude_events')) {
            $val = request()->query('exclude_events');
            $this->filters['exclude_events'] = ($val === '1' || $val === 'true');
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        $schema = parent::filtersForm($schema);
        $components = $schema->getComponents();

        // Adjust preset default to Last 8 weeks
        foreach ($components as $component) {
            if ($component instanceof Select && $component->getName() === 'preset') {
                $component->default(PeriodPreset::last_8_weeks->value);
            }
        }

        // Add channel filter
        $components[] = Select::make('channel')
            ->label('قناة البيع')
            ->options([
                'dine_in' => 'صالة (Dine-in)',
                'takeaway' => 'سفري (Takeaway)',
                'delivery' => 'توصيل (Delivery)',
            ])
            ->placeholder('كل القنوات')
            ->nullable();

        // Add exclude calendar events toggle
        $components[] = Toggle::make('exclude_events')
            ->label('استبعاد المناسبات (رمضان / العيد)')
            ->default(true);

        return $schema->components($components);
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            ShiftsKpiOverviewWidget::class,
            WeekdayProfileChartWidget::class,
            HourlyHeatmapWidget::class,
            PeakHoursChartWidget::class,
            ShiftSlotsTableWidget::class,
            CashierSummaryTableWidget::class,
            ShiftsTableWidget::class,
        ];
    }
}
