<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\DataHealthReport;

final readonly class BranchCalendarRow
{
    /**
     * @param  array<string, CalendarDayCell>  $days  keyed by Y-m-d date
     */
    public function __construct(
        public int $branchId,
        public string $branchName,
        public int $completeDays,
        public int $totalDays,
        public array $days,
    ) {}
}
