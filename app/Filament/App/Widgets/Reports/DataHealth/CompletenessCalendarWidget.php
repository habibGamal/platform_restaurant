<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\DataHealth;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\DataHealthReport;
use App\Domain\Metrics\Reports\DataHealthReport\BranchCalendarRow;
use App\Filament\App\Widgets\Reports\BaseReportViewWidget;
use App\Models\User;

class CompletenessCalendarWidget extends BaseReportViewWidget
{
    protected string $view = 'filament.app.reports.data-health.completeness-calendar-widget';

    protected ?string $heading = 'تقويم اكتمال وجودة البيانات';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, string>
     */
    protected function getColumns(): array
    {
        $calendar = $this->getCalendarData();
        $cols = ['branch' => 'الفرع', 'achievement' => 'الإنجاز'];

        foreach ($calendar['dates'] as $date) {
            $cols[$date] = $date;
        }

        return $cols;
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    protected function getRows(): array
    {
        $calendar = $this->getCalendarData();
        $rows = [];

        foreach ($calendar['branches'] as $branchRow) {
            $row = [
                'branch' => $branchRow->branchName,
                'achievement' => "{$branchRow->completeDays}/{$branchRow->totalDays}",
            ];

            foreach ($calendar['dates'] as $date) {
                $status = $branchRow->days[$date]->status ?? 'missing';
                $row[$date] = "<x-reports.completeness-dot status='{$status}' />";
            }

            $rows[$branchRow->branchId] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $calendar = $this->getCalendarData();

        return [
            'heading' => $this->getHeading(),
            'coverage' => $calendar['coverage'],
            'branches' => $calendar['branches'],
            'dates' => $calendar['dates'],
            'columns' => $this->getColumns(),
            'rows' => $this->getRows(),
        ];
    }

    /**
     * @return array{
     *     branches: list<BranchCalendarRow>,
     *     dates: list<string>,
     *     coverage: array{complete: int, total: int}
     * }
     */
    private function getCalendarData(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [
                'branches' => [],
                'dates' => [],
                'coverage' => ['complete' => 0, 'total' => 0],
            ];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = DataHealthReport::for($query);

        return $report->completenessCalendar();
    }
}
