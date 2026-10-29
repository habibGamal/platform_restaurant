<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\TargetsVsActualReport;

final readonly class TargetHistoryCell
{
    public function __construct(
        public string $monthKey,
        public ?float $target,
        public ?float $actual,
        public ?float $achievement,
        public string $status,
    ) {}
}
