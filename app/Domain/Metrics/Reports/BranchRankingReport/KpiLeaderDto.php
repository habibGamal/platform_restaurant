<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\BranchRankingReport;

final readonly class KpiLeaderDto
{
    public function __construct(
        public string $kpiKey,
        public string $kpiLabel,
        public string $direction,
        public string $bestBranchName,
        public ?float $bestValue,
        public string $bestFormatted,
        public string $worstBranchName,
        public ?float $worstValue,
        public string $worstFormatted,
    ) {}
}
