<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\BranchRankingReport;

final readonly class ExcludedBranchDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $reason,
        public int $completeDays,
        public int $totalDays,
        public string $status,
    ) {}
}
