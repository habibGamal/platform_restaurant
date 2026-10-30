<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ProductPerformanceReport;

final readonly class CrossBranchMatrix
{
    /**
     * @param  list<array{id: int, name: string}>  $branches
     * @param  list<array{
     *     itemKey: string,
     *     productRef: ?string,
     *     name: string,
     *     categoryName: string,
     *     branches: array<int, array{qty: float, sales: float, daysOpen: int, perDay: float}>,
     *     rowTotal: float,
     *     rowMaxPerDay: float
     * }>  $rows
     */
    public function __construct(
        public array $branches,
        public array $rows,
        public string $metric = 'qty',
    ) {}
}
