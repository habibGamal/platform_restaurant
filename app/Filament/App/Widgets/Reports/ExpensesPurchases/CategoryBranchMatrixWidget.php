<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ExpensesPurchases;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\CategoryBranchMatrixDto;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;

class CategoryBranchMatrixWidget extends BaseReportViewWidget
{
    protected string $view = 'filament.app.reports.expenses-purchases.category-matrix-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = true;

    protected ?string $heading = 'مصفوفة المصروفات حسب التصنيف والفروع';

    /**
     * @return array<string, string>
     */
    protected function getColumns(): array
    {
        return [];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    protected function getRows(): array
    {
        return [];
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
