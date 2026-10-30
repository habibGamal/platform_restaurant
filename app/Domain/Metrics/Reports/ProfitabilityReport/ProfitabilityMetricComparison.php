<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProfitabilityReport;

final readonly class ProfitabilityMetricComparison
{
    public function __construct(
        public ?float $current,
        public ?float $comparison,
        public ?float $delta,
        public ?float $growth,
        public ?float $marginPointsDelta = null,
    ) {}
}
