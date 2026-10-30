<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class PurchasesPerBranchDto
{
    /**
     * @param  array<string, float>  $weeklyTrend
     */
    public function __construct(
        public int $branchId,
        public string $branchName,
        public float $invoicesAmount,
        public int $invoicesCount,
        public float $returnsAmount,
        public int $returnsCount,
        public float $netPurchases,
        public array $weeklyTrend,
    ) {}
}
