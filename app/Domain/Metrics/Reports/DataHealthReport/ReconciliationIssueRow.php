<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\DataHealthReport;

final readonly class ReconciliationIssueRow
{
    public function __construct(
        public int $id,
        public int $branchId,
        public string $branchName,
        public string $date,
        public string $field,
        public string $fieldLabel,
        public ?float $posValue,
        public ?float $platformValue,
        public float $diff,
        public int $attempts,
        public string $status,
        public string $statusLabel,
    ) {}
}
