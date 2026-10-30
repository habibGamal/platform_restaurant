<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class WeekdayProfileData
{
    /**
     * @param  list<string>  $labels
     * @param  list<WeekdayProfileDataset>  $datasets
     */
    public function __construct(
        public array $labels,
        public array $datasets,
    ) {}
}
