<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\BranchRankingReport;

final readonly class RankHistoryData
{
    /**
     * @param  list<string>  $labels
     * @param  list<array<string, mixed>>  $datasets
     */
    public function __construct(
        public array $labels,
        public array $datasets,
    ) {}
}
