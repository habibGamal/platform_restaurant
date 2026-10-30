<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\DataHealthReport;

final readonly class DataHealthSummary
{
    public function __construct(
        public int $totalBranches,
        public int $onlineBranches,
        public int $delayedBranches,
        public int $offlineBranches,
        public int $totalDays,
        public int $completeDays,
        public int $openReconciliationIssues,
        public int $rejectedDocs7Days,
    ) {}
}
