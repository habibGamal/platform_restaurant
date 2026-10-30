<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class PeakHoursData
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
