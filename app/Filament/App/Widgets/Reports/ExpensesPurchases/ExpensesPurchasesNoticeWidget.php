<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\ExpensesPurchases;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport;
use App\Filament\App\Resources\ExpenseCategoryMappingResource;
use App\Filament\App\Widgets\Concerns\InteractsWithReportFilters;
use App\Models\User;
use Filament\Widgets\Widget;

class ExpensesPurchasesNoticeWidget extends Widget
{
    use InteractsWithReportFilters;

    protected string $view = 'filament.app.reports.expenses-purchases.notice-widget';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [
                'uncategorizedCount' => 0,
                'mappingUrl' => null,
            ];
        }

        $reportFilters = $this->reportFilters();
        $query = MetricsQuery::for($user, $reportFilters);
        $report = ExpensesPurchasesReport::for($query);

        $uncategorizedCount = $report->uncategorizedExpenseTypesCount();

        $mappingUrl = null;
        try {
            $mappingUrl = ExpenseCategoryMappingResource::getUrl('index');
        } catch (\Throwable) {
            $mappingUrl = null;
        }

        return [
            'uncategorizedCount' => $uncategorizedCount,
            'mappingUrl' => $mappingUrl,
        ];
    }
}
