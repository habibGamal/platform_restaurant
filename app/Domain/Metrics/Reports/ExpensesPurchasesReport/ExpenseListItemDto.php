<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ExpensesPurchasesReport;

final readonly class ExpenseListItemDto
{
    public function __construct(
        public int $id,
        public string $businessDate,
        public int $branchId,
        public string $branchName,
        public ?string $shiftUuid,
        public ?string $expenseTypeUuid,
        public string $expenseTypeName,
        public string $category,
        public string $categoryLabel,
        public float $amount,
        public ?string $notes,
    ) {}
}
