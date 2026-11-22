<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\FoodCostWaste;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\FoodCostWasteReport;
use App\Filament\App\Widgets\Reports\BaseReportTableWidget;
use App\Models\User;
use App\Support\Format;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FoodCostBranchMatrixWidget extends BaseReportTableWidget
{
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading('مصفوفة مؤشرات التكلفة والهالك لكل فرع')
            ->description('مقارنة نسب تكلفة الخامات والهالك وعجز الجرد لكل فرع مقابل النطاق المستهدف (28% - 35%)')
            ->paginated([15, 30, 50, 100]);
    }

    protected function getReportRecords(): ?Closure
    {
        return function (): array {
            /** @var User|null $user */
            $user = auth()->user();
            if ($user === null) {
                return [];
            }

            $reportFilters = $this->reportFilters();
            $query = MetricsQuery::for($user, $reportFilters);
            $report = FoodCostWasteReport::for($query);

            $rows = $report->branchMatrix();
            $groupBenchmark = $report->groupBenchmark();

            $records = [];
            foreach ($rows as $row) {
                $records[$row->branchId] = [
                    'id' => $row->branchId,
                    'branch_name' => $row->branchName,
                    'food_cost_pct' => Format::percent($row->foodCostPct),
                    'waste_pct' => $row->wasteRecorded
                        ? Format::percent($row->wastePct)
                        : '0.0% ⚠️ لا يتم تسجيل هالك',
                    'waste_share_of_usage' => Format::percent($row->wasteShareOfUsage),
                    'inventory_variance_cost' => Format::money($row->inventoryVarianceCost),
                    'inventory_variance_pct' => Format::percent($row->inventoryVariancePct),
                    'total_loss_pct' => Format::percent($row->totalLossPct),
                    'band_status' => match ($row->targetBandStatus) {
                        'below_band' => 'أقل من النطاق (ممتاز)',
                        'in_band' => 'ضمن النطاق المستهدف',
                        'above_band' => 'مرتفع (أعلى من 35%)',
                        default => '—',
                    },
                    'band_color' => $row->targetBandColor,
                ];
            }

            if ($groupBenchmark !== null) {
                $records['group'] = [
                    'id' => 'group',
                    'branch_name' => $groupBenchmark->branchName,
                    'food_cost_pct' => Format::percent($groupBenchmark->foodCostPct),
                    'waste_pct' => $groupBenchmark->wasteRecorded
                        ? Format::percent($groupBenchmark->wastePct)
                        : '0.0% ⚠️ لا يتم تسجيل هالك',
                    'waste_share_of_usage' => Format::percent($groupBenchmark->wasteShareOfUsage),
                    'inventory_variance_cost' => Format::money($groupBenchmark->inventoryVarianceCost),
                    'inventory_variance_pct' => Format::percent($groupBenchmark->inventoryVariancePct),
                    'total_loss_pct' => Format::percent($groupBenchmark->totalLossPct),
                    'band_status' => match ($groupBenchmark->targetBandStatus) {
                        'below_band' => 'أقل من النطاق (ممتاز)',
                        'in_band' => 'ضمن النطاق المستهدف',
                        'above_band' => 'مرتفع (أعلى من 35%)',
                        default => '—',
                    },
                    'band_color' => $groupBenchmark->targetBandColor,
                ];
            }

            return $records;
        };
    }

    protected function getReportColumns(): array
    {
        return [
            TextColumn::make('branch_name')
                ->label('الفرع')
                ->weight('bold'),
            TextColumn::make('food_cost_pct')
                ->label('تكلفة الخامات %')
                ->badge()
                ->color(fn (array $record): string => $record['band_color'] ?? 'gray'),
            TextColumn::make('waste_pct')
                ->label('نسبة الهالك'),
            TextColumn::make('waste_share_of_usage')
                ->label('حصة الهالك من الاستهلاك'),
            TextColumn::make('inventory_variance_cost')
                ->label('عجز الجرد'),
            TextColumn::make('inventory_variance_pct')
                ->label('عجز الجرد %'),
            TextColumn::make('total_loss_pct')
                ->label('إجمالي الفاقد %'),
            TextColumn::make('band_status')
                ->label('تقييم النطاق')
                ->badge()
                ->color(fn (array $record): string => $record['band_color'] ?? 'gray'),
        ];
    }
}
