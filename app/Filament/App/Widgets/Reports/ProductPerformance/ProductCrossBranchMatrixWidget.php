<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ProductPerformance;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ProductPerformanceReport;
use App\Domain\Metrics\Reports\ProductPerformanceReport\CrossBranchMatrix;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductCrossBranchMatrixWidget extends BaseReportViewWidget
{
    protected string $view = 'filament.app.reports.product-performance.cross-branch-matrix';

    public string $metric = 'qty'; // 'qty' | 'sales'

    public function setMetric(string $metric): void
    {
        if (in_array($metric, ['qty', 'sales'], true)) {
            $this->metric = $metric;
        }
    }

    private function getMatrixData(): CrossBranchMatrix
    {
        /** @var User|null $user */
        $user = auth()->user();
        if (! $user) {
            return new CrossBranchMatrix([], [], $this->metric);
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = ProductPerformanceReport::for($query);

        return $report->crossBranchMatrix($this->metric);
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->heading(null);
    }

    protected function getTableColumnsForRecords(): array
    {
        $columns = [];

        foreach ($this->getColumns() as $key => $label) {
            $minWidth = match ($key) {
                'name' => 'min-w-[180px]',
                'category' => 'min-w-[120px]',
                'total' => 'min-w-[140px]',
                default => 'min-w-[140px]',
            };

            $columns[] = TextColumn::make($key)
                ->label($label)
                ->html()
                ->placeholder('—')
                ->extraHeaderAttributes(['class' => "whitespace-nowrap {$minWidth}"])
                ->extraCellAttributes(['class' => 'whitespace-nowrap']);
        }

        return $columns;
    }

    protected function getColumns(): array
    {
        $matrix = $this->getMatrixData();

        $columns = [
            'name' => 'الصنف',
            'category' => 'التصنيف',
        ];

        foreach ($matrix->branches as $branch) {
            $columns["branch_{$branch['id']}"] = $branch['name'];
        }

        $columns['total'] = $this->metric === 'sales' ? 'إجمالي المبيعات' : 'إجمالي الكمية';

        return $columns;
    }

    protected function getRows(): array
    {
        $matrix = $this->getMatrixData();

        $rows = [];
        foreach ($matrix->rows as $item) {
            $rowMax = $item['rowMaxPerDay'];

            $rowData = [
                'name' => '<div class="font-medium text-gray-900 dark:text-white">'.e($item['name']).'</div>'
                    .($item['productRef'] ? '<span class="text-xs text-gray-500 font-mono">'.e($item['productRef']).'</span>' : ''),
                'category' => '<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-white/10 dark:text-gray-300">'.e($item['categoryName']).'</span>',
            ];

            foreach ($matrix->branches as $branch) {
                $bId = $branch['id'];
                $branchStats = $item['branches'][$bId] ?? null;

                if (! $branchStats || ($branchStats['qty'] <= 0 && $branchStats['sales'] <= 0)) {
                    $rowData["branch_{$bId}"] = '<span class="text-gray-400 dark:text-gray-600">—</span>';

                    continue;
                }

                $perDay = $branchStats['perDay'];
                $ratio = $rowMax > 0 ? ($perDay / $rowMax) : 0.0;

                $bgClass = match (true) {
                    $ratio >= 0.75 => 'bg-emerald-100 text-emerald-950 dark:bg-emerald-500/25 dark:text-emerald-200 font-semibold',
                    $ratio >= 0.40 => 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
                    default => 'bg-gray-50 text-gray-700 dark:bg-white/5 dark:text-gray-300',
                };

                $formattedPerDay = $this->metric === 'sales'
                    ? Format::money($perDay, 0).' / يوم'
                    : Format::number($perDay, 1).' / يوم';

                $rawTotal = $this->metric === 'sales'
                    ? Format::money($branchStats['sales'], 0)
                    : Format::number($branchStats['qty'], 0);

                $rowData["branch_{$bId}"] = "
                    <div class=\"inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md {$bgClass} text-xs font-mono\">
                        <span>{$formattedPerDay}</span>
                        <span class=\"text-[10px] opacity-75\">({$rawTotal})</span>
                    </div>
                ";
            }

            $formattedRowTotal = $this->metric === 'sales'
                ? Format::money($item['rowTotal'], 2)
                : Format::number($item['rowTotal'], 0);

            $rowData['total'] = '<span class="font-bold text-gray-900 dark:text-white font-mono">'.$formattedRowTotal.'</span>';

            $rows[] = $rowData;
        }

        return $rows;
    }

    protected function getViewData(): array
    {
        return [
            'heading' => $this->getHeading(),
            'columns' => $this->getColumns(),
            'rows' => $this->getRows(),
            'metric' => $this->metric,
        ];
    }
}
