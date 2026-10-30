<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\SalesComparisonReport;

final readonly class SalesTrendData
{
    /**
     * @param  list<string>  $labels
     * @param  list<array{branch_id: int, label: string, data: list<float>}>  $datasets
     */
    public function __construct(
        public array $labels,
        public array $datasets,
    ) {}
}
