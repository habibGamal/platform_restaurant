<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\TargetsVsActualReport;

final readonly class TargetsVsActualData
{
    /**
     * @param  array<string, TargetOverviewMetric>  $overview
     * @param  list<TargetBranchTableRow>  $branchRows
     * @param  array<string, TargetBurnUpSeries>  $burnUpSeries
     * @param  list<string>  $historyMonths
     * @param  array<string, string>  $historyMonthLabels
     * @param  list<TargetHistoryRow>  $historyRows
     */
    public function __construct(
        public array $overview,
        public array $branchRows,
        public array $burnUpSeries,
        public array $historyMonths,
        public array $historyMonthLabels,
        public array $historyRows,
    ) {}
}
