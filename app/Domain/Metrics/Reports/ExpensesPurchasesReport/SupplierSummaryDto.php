<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class SupplierSummaryDto
{
    /**
     * @param  list<string>  $branchNames
     */
    public function __construct(
        public string $supplierKey,
        public string $supplierName,
        public int $branchesCount,
        public array $branchNames,
        public int $invoicesCount,
        public float $invoicesAmount,
        public float $returnsAmount,
        public float $netPurchases,
        public ?float $shareOfPurchases,
    ) {}
}
