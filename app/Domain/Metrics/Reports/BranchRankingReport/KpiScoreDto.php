<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\BranchRankingReport;

final readonly class KpiScoreDto
{
    public function __construct(
        public string $kpiKey,
        public string $kpiLabel,
        public ?float $rawValue,
        public string $formattedValue,
        public ?float $score,
        public float $weight,
        public float $effectiveWeight,
        public string $direction,
        public bool $isUnavailable = false,
    ) {}
}
