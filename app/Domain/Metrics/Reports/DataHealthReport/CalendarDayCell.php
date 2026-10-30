<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\DataHealthReport;

final readonly class CalendarDayCell
{
    /**
     * @param  'complete'|'closed'|'open'|'missing'|'not_opened'  $status
     */
    public function __construct(
        public string $date,
        public string $status,
        public ?int $branchId = null,
        public ?string $label = null,
    ) {}
}
