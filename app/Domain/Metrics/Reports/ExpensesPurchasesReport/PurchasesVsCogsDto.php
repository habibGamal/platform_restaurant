<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class PurchasesVsCogsDto
{
    public function __construct(
        public int $branchId,
        public string $branchName,
        public float $netPurchases,
        public float $cogs,
        public ?float $ratio,
        public string $status, // 'building_up' | 'running_down' | 'balanced' | 'no_data'
        public string $statusLabel,
        public string $tooltip,
    ) {}
}
