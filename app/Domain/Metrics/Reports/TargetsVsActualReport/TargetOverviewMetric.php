<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\TargetsVsActualReport;

final readonly class TargetOverviewMetric
{
    /**
     * @param  list<float|int>  $sparkline
     */
    public function __construct(
        public string $metric,
        public string $label,
        public ?float $targetMonth,
        public ?float $targetToDate,
        public float $actualToDate,
        public ?float $achievementToDate,
        public ?float $monthAchievement,
        public ?float $gap,
        public ?float $projected,
        public ?float $projectedAchievement,
        public string $status,
        public int $branchesWithTarget,
        public int $branchesTotal,
        public ?string $note = null,
        public array $sparkline = [],
    ) {}
}
