<?php

declare(strict_types=1);

namespace App\Filament\App\Widgets\Reports\DataHealth;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Reports\DataHealthReport;
use App\Filament\App\Widgets\Reports\BaseKpiStatsWidget;
use App\Models\User;
use App\Support\Format;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DataHealthOverviewWidget extends BaseKpiStatsWidget
{
    protected function getStats(): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        $query = MetricsQuery::for($user, $this->reportFilters());
        $report = DataHealthReport::for($query);
        $summary = $report->summary();

        $connDesc = match (true) {
            $summary->offlineBranches > 0 => "{$summary->offlineBranches} فروع غير متصلة",
            $summary->delayedBranches > 0 => "{$summary->delayedBranches} فروع متأخرة",
            default => 'جميع الفروع متصلة وتعمل',
        };

        $connColor = match (true) {
            $summary->offlineBranches > 0 => 'danger',
            $summary->delayedBranches > 0 => 'warning',
            default => 'success',
        };

        $pctComplete = $summary->totalDays > 0
            ? round(($summary->completeDays / $summary->totalDays) * 100, 1)
            : 100.0;

        $coverageDesc = "{$summary->completeDays} من {$summary->totalDays} يوم مكتمل ({$pctComplete}%)";
        $coverageColor = match (true) {
            $pctComplete >= 95.0 => 'success',
            $pctComplete >= 80.0 => 'warning',
            default => 'danger',
        };

        return [
            Stat::make('حالة الاتصال', "{$summary->onlineBranches} / {$summary->totalBranches}")
                ->description($connDesc)
                ->descriptionColor($connColor)
                ->color($connColor),

            Stat::make('اكتمال البيانات', "{$pctComplete}%")
                ->description($coverageDesc)
                ->descriptionColor($coverageColor)
                ->color($coverageColor),

            Stat::make('مشاكل المطابقة المعلقة', Format::number((float) $summary->openReconciliationIssues))
                ->description($summary->openReconciliationIssues > 0 ? 'تتطلب إعادة مزامنة' : 'لا توجد فروقات مطابقة معلقة')
                ->descriptionColor($summary->openReconciliationIssues > 0 ? 'danger' : 'success')
                ->color($summary->openReconciliationIssues > 0 ? 'danger' : 'success'),

            Stat::make('المرفوضات (7 أيام)', Format::number((float) $summary->rejectedDocs7Days))
                ->description($summary->rejectedDocs7Days > 0 ? 'مستندات رُفضت من POS' : 'لا توجد مستندات مرفوضة')
                ->descriptionColor($summary->rejectedDocs7Days > 0 ? 'danger' : 'success')
                ->color($summary->rejectedDocs7Days > 0 ? 'danger' : 'success'),
        ];
    }
}
