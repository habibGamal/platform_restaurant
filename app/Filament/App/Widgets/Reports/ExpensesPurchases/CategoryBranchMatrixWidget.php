<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ExpensesPurchases;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\CategoryBranchMatrixDto;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;
use App\Support\Format;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class CategoryBranchMatrixWidget extends BaseReportViewWidget
{
    protected string $view = 'filament.app.reports.expenses-purchases.category-matrix-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    protected ?string $heading = 'مصفوفة المصروفات حسب التصنيف والفروع';

    protected bool $hasTableSearchAndSorting = false;

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->description('توزيع المصروفات التشغيلية ونسبة كل بند من مبيعات الفرع — العمالة K11a')
            ->recordClasses(fn (array $record): ?string => ($record['_is_total'] ?? false) ? 'font-bold bg-primary-50/50 dark:bg-primary-950/20' : null);
    }

    protected function getTableColumnsForRecords(): array
    {
        $matrix = $this->getViewData()['matrix'];
        $columns = [TextColumn::make('category')->label('التصنيف')->html()];

        foreach ($matrix->branches as $branch) {
            $columns[] = TextColumn::make("branch_{$branch['id']}")
                ->label(new HtmlString('<div>'.e($branch['name']).'</div><div class="text-xs font-normal text-gray-500">مبيعات: '.e(Format::money($branch['sales'])).'</div>'))
                ->html()
                ->extraHeaderAttributes(['class' => 'whitespace-nowrap min-w-[160px]'])
                ->extraCellAttributes(['class' => 'whitespace-nowrap']);
        }

        if ($matrix->grandTotal !== null) {
            $columns[] = TextColumn::make('group')->label('إجمالي الفروع (المجموعة)')->html()
                ->extraCellAttributes(['class' => 'whitespace-nowrap font-semibold']);
        }

        return $columns;
    }

    /**
     * @return array<string, string>
     */
    protected function getColumns(): array
    {
        $viewData = $this->getViewData();
        /** @var CategoryBranchMatrixDto $matrix */
        $matrix = $viewData['matrix'];

        $columns = ['category' => 'التصنيف'];
        foreach ($matrix->branches as $branch) {
            $columns["branch_{$branch['id']}"] = $branch['name'];
        }

        if ($matrix->grandTotal !== null) {
            $columns['group'] = 'إجمالي الفروع (المجموعة)';
        }

        return $columns;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    protected function getRows(): array
    {
        $viewData = $this->getViewData();
        /** @var CategoryBranchMatrixDto $matrix */
        $matrix = $viewData['matrix'];

        $rows = [];
        foreach ($matrix->categories as $category) {
            $catLabel = $matrix->categoryLabels[$category] ?? $category;
            $catBadge = $category === 'labor' ? ' <span class="text-[10px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">K11a</span>' : '';

            $row = [
                'category' => '<div class="flex items-center gap-2 font-medium text-gray-900 dark:text-white"><span>'.e($catLabel).'</span>'.$catBadge.'</div>',
            ];

            foreach ($matrix->branches as $branch) {
                $bId = $branch['id'];
                $cell = $matrix->cells[$category][$bId] ?? ['amount' => 0.0, 'percentOfSales' => null];
                $amount = Format::money((float) $cell['amount']);
                $pct = Format::percent($cell['percentOfSales']);

                $row["branch_{$bId}"] = '
                    <div class="flex flex-col text-center">
                        <span class="font-medium text-gray-900 dark:text-white">'.$amount.'</span>
                        <span class="text-[11px] text-gray-500 dark:text-gray-400">'.$pct.'</span>
                    </div>';
            }

            if ($matrix->grandTotal !== null) {
                $catTotal = $matrix->categoryTotals[$category] ?? ['amount' => 0.0, 'percentOfSales' => null];
                $totalAmount = Format::money((float) $catTotal['amount']);
                $totalPct = Format::percent($catTotal['percentOfSales']);

                $row['group'] = '
                    <div class="flex flex-col text-center font-semibold">
                        <span class="text-primary-950 dark:text-primary-200">'.$totalAmount.'</span>
                        <span class="text-[11px] text-primary-600/80 dark:text-primary-400/80">'.$totalPct.'</span>
                    </div>';
            }

            $rows[$category] = $row;
        }

        if ($matrix->categories !== []) {
            $total = ['_is_total' => true, 'category' => 'الإجمالي (K11)'];
            foreach ($matrix->branches as $branch) {
                $cell = $matrix->branchTotals[$branch['id']] ?? ['amount' => 0.0, 'percentOfSales' => null];
                $total["branch_{$branch['id']}"] = '<div>'.e(Format::money((float) $cell['amount'])).'</div><div class="text-xs">'.e(Format::percent($cell['percentOfSales'])).'</div>';
            }
            if ($matrix->grandTotal !== null) {
                $total['group'] = '<div>'.e(Format::money((float) $matrix->grandTotal['amount'])).'</div><div class="text-xs">'.e(Format::percent($matrix->grandTotal['percentOfSales'])).'</div>';
            }
            $rows['_total'] = $total;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            $matrix = new CategoryBranchMatrixDto(
                branches: [],
                categories: [],
                categoryLabels: [],
                cells: [],
                branchTotals: [],
                categoryTotals: [],
                grandTotal: null,
            );

            return [
                'heading' => $this->getHeading(),
                'matrix' => $matrix,
            ];
        }

        $reportFilters = $this->reportFilters();
        $query = MetricsQuery::for($user, $reportFilters);
        $report = ExpensesPurchasesReport::for($query);

        return [
            'heading' => $this->getHeading(),
            'matrix' => $report->categoryMatrix(),
        ];
    }
}
