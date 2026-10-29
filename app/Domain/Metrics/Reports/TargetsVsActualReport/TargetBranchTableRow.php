<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\TargetsVsActualReport;

final readonly class TargetBranchTableRow
{
    public function __construct(
        public ?int $branchId,
        public string $branchName,
        public string $metric,
        public string $metricLabel,
        public ?float $targetMonth,
        public ?float $targetToDate,
        public float $actualToDate,
        public ?float $achievementToDate,
        public ?float $monthAchievement,
        public ?float $gap,
        public ?float $projected,
        public ?float $projectedAchievement,
        public ?float $requiredDailyAverage,
        public ?float $currentDailyAverage,
        public string $status,
        public bool $isGroup = false,
    ) {}
}
