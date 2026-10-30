<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\SalesComparisonReport;

final readonly class SalesKpiMatrixRow
{
    public function __construct(
        public ?int $branchId,
        public string $branchName,
        public bool $isGroupAverage,
        public ?float $sales,
        public ?float $salesComparison,
        public ?float $salesGrowth,
        public ?int $orders,
        public ?int $ordersComparison,
        public ?float $ordersGrowth,
        public ?float $avgTicket,
        public ?float $avgTicketComparison,
        public ?float $avgTicketGrowth,
        public ?float $discounts,
        public ?float $discountsComparison,
        public ?float $discountsGrowth,
        public ?float $cancelRate,
        public ?float $cancelRateComparison,
        public ?float $cancelRateGrowth,
        public ?float $returnRate,
        public ?float $returnRateComparison,
        public ?float $returnRateGrowth,
        public int $daysWithData,
        public ?int $daysWithDataComparison,
    ) {}
}
