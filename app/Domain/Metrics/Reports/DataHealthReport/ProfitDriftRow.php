<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\DataHealthReport;

final readonly class ProfitDriftRow
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public string $date,
        public float $posProfit,
        public float $platformProfit,
        public float $diff,
        public float $diffPercent,
    ) {}
}
