<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProfitabilityReport;

use App\Domain\Metrics\Period\Period;

final readonly class ProfitabilityReportData
{
    /**
     * @param  array<int, BranchProfitabilityData>  $branches  Keyed by branch ID
     * @param  list<string>  $stocktakingNotes
     * @param  list<string>  $missingExpenseBranches
     */
    public function __construct(
        public Period $period,
        public ?Period $comparisonPeriod,
        public string $laborBasis,
        public bool $excludeInventoryVariance,
        public array $branches,
        public ?BranchProfitabilityData $group,
        public array $stocktakingNotes = [],
        public array $missingExpenseBranches = [],
    ) {}
}
