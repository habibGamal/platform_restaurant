<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\BranchRankingReport;

final readonly class BranchRankingData
{
    /**
     * @param  list<BranchRankingRowDto>  $rankedBranches
     * @param  list<ExcludedBranchDto>  $excludedBranches
     * @param  list<BranchRankingRowDto>  $top3Podium
     * @param  list<BranchRankingRowDto>  $bottom3Podium
     * @param  list<KpiLeaderDto>  $kpiLeaders
     * @param  array<string, float>  $weights
     */
    public function __construct(
        public array $rankedBranches,
        public array $excludedBranches,
        public array $top3Podium,
        public array $bottom3Podium,
        public array $kpiLeaders,
        public RankHistoryData $rankHistory,
        public bool $singleBranchMode,
        public bool $canSeeOtherBranchNames,
        public bool $canSeeGroup,
        public array $weights,
    ) {}
}
