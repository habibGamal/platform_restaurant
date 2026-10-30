<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\BranchRankingReport;

final readonly class BranchRankingRowDto
{
    /**
     * @param  array<string, KpiScoreDto>  $kpiScores  keyed by KPI key
     * @param  list<KpiScoreDto>  $helpedKpis
     * @param  list<KpiScoreDto>  $hurtKpis
     */
    public function __construct(
        public int $branchId,
        public string $branchName,
        public bool $isAnonymized,
        public bool $isCurrentManagerBranch,
        public ?float $overallScore,
        public ?int $rank,
        public ?int $previousRank,
        public ?int $rankDelta,
        public string $rankDeltaFormatted,
        public string $rankDeltaColor,
        public bool $hasUnavailableKpi,
        public array $kpiScores,
        public array $helpedKpis,
        public array $hurtKpis,
        public bool $isComplete = true,
        public ?string $exclusionReason = null,
    ) {}
}
