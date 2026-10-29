### 4. Exact API Report Agents Must Use

#### A. Base Report Page (`ReportPage`)
Each concrete report extends `ReportPage` and specifies:
```php
namespace App\Filament\App\Pages\Reports;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

class SalesComparison extends ReportPage
{
    protected static string $routePath = 'reports/sales';
    protected static ?string $title = 'مقارنة المبيعات';
    protected static ?string $navigationLabel = 'مقارنة المبيعات';
    protected static ?int $navigationSort = 2;
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    public function getWidgets(): array
    {
        return [
            SalesKpiOverviewWidget::class,
            SalesTrendChartWidget::class,
            SalesBranchTableWidget::class,
        ];
    }
}
```
Accessing filters from the page:
```php
$reportFilters = $this->reportFilters(); // Returns App\Domain\Metrics\Period\ReportFilters
```

#### B. Report Filter Trait (`InteractsWithReportFilters`)
Use in any custom Livewire widget on a report page:
```php
use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;

$filters = $this->reportFilters();
$preset = $filters->preset;                  // PeriodPreset enum
$period = $filters->period;                  // Period (from, to, cutoffTime)
$comparison = $filters->comparison;          // ?Period
$branchIds = $filters->branchIds;            // ?int[]
$likeForLike = $filters->likeForLike;        // bool
$granularity = $filters->granularity;        // Granularity enum (day, week, month)
```

#### C. KPI Cards Widget (`BaseKpiStatsWidget`)
Extends `StatsOverviewWidget`, `$isLazy = false`.
```php
namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesKpiOverviewWidget extends BaseKpiStatsWidget
{
    protected function getStats(): array
    {
        $filters = $this->reportFilters();
        // Query domain KPIs...

        return [
            $this->kpiStat(
                label: 'صافي المبيعات',
                value: 48210.0,
                comparison: 42850.0,
                format: 'money',          // 'money' | 'percent' | 'number'
                higherIsBetter: true,     // false for costs/waste/cancellation
                sparkline: [35, 40, 42, 45, 48],
            ),
        ];
    }
}
```
Helper signature:
```php
protected function kpiStat(
    string $label,
    ?float $value,
    ?float $comparison,
    string $format = 'money',
    bool $higherIsBetter = true,
    array $sparkline = []
): Stat;
```

#### D. Chart Widgets (`BaseReportChartWidget`)
Extends `ChartWidget`, `$isLazy = true`.
```php
namespace App\Filament\App\Widgets\Reports\SalesComparison;

use App\Filament\App\Widgets\Reports\BaseReportChartWidget;

class SalesTrendChartWidget extends BaseReportChartWidget
{
    protected ?string $heading = 'اتجاه المبيعات اليومية';

    protected function getType(): string
    {
        return 'line'; // 'line' | 'bar'
    }

    protected function getData(): array
    {
        $filters = $this->reportFilters();

        return [
            'datasets' => [
                $this->branchLineDataset(branchId: 1, label: 'فرع المعادي', data: [12000, 14000, 16000]),
                $this->branchLineDataset(branchId: 2, label: 'فرع الزمالك', data: [9000, 11000, 10500]),
            ],
            'labels' => ['السبت', 'الأحد', 'الاثنين'],
        ];
    }
}
```
Available helpers:
- `branchLineDataset(int $branchId, string $label, array $data, array $extra = []): array`
- `branchBarDataset(int $branchId, string $label, array $data, array $extra = []): array`
- `lineDataset(string $label, array $data, ?string $color = null, array $extra = []): array`
- `barDataset(string $label, array $data, ?string $color = null, array $extra = []): array`

#### E. Table Widgets (`BaseReportTableWidget` & `BaseReportViewWidget`)
1. **Primary Pattern (Filament Custom Array Records):**
   Extend `BaseReportTableWidget` (`$isLazy = true`).
   ```php
   use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
   use Filament\Tables\Columns\TextColumn;
   use Closure;

   class SalesBranchTableWidget extends BaseReportTableWidget
   {
       protected function getReportRecords(): ?Closure
       {
           return fn (): array => [
               1 => ['branch' => 'المعادي', 'sales' => '48,210 ج.م', 'growth' => '↑ +12.5%'],
               2 => ['branch' => 'الزمالك', 'sales' => '32,100 ج.م', 'growth' => '↓ -4.1%'],
           ];
       }

       protected function getReportColumns(): array
       {
           return [
               TextColumn::make('branch')->label('الفرع'),
               TextColumn::make('sales')->label('المبيعات'),
               TextColumn::make('growth')->label('النمو'),
           ];
       }
   }
   ```
2. **Alternative Pattern (Custom Matrix / Blade Table):**
   Extend `BaseReportViewWidget` (`$isLazy = true`) if complex non-standard layouts or matrix tables are needed. Implement `getColumns(): array` and `getRows(): array`.

#### F. Helper Utilities & Blade Components
- **`Format`:**
  - `Format::money(?float $amount, int $decimals = 0): string`
  - `Format::percent(?float $ratio, int $decimals = 1): string` (Expects decimal ratio, e.g. `0.125` $\to$ `"12.5%"`)
  - `Format::number(?float $number, int $decimals = 0): string`
  - `Format::growth(?float $growth): string` (e.g. `"↑ +12.5%"`, `"↓ -5.2%"`, `"0.0%"`, or `"—"`)
- **`BranchColor`:**
  - `BranchColor::for(int $branchId): string` (Returns consistent hex color)
  - `BranchColor::palette(): list<string>`
- **Blade Components:**
  - `<x-reports.completeness-dot :status="'complete'|'closed'|'open'|'missing'|'not_opened'" />`
  - `<x-reports.coverage :complete="18" :total="20" />`

---

### Decisions & Open Questions
- **Decisions:**
  - Standardized DatePicker internal format handling by stripping time components in `reportFilters()`. This guarantees compatibility between Filament's Alpine/flatpickr component and `ReportFilters::parseDate()` (`/^\d{4}-\d{2}-\d{2}$/`).
  - Set `$isLazy = true` as default for charts, tables, and view widgets, keeping `$isLazy = false` on stats cards and placeholder notices for optimal initial render performance.
  - Placed report filter controls inside a collapsible `Section` titled `'فلاتر التقرير'` with a responsive 4-column layout (`default: 1`, `sm: 2`, `md: 3`, `xl: 4`).
- **Open Questions:**
  - None; all 10 report agents can now immediately implement their respective pages and widgets extending these base classes.