<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\ShiftsWeekdaysReport;

final readonly class WeekdayProfileDataset
{
    /**
     * @param  list<float>  $data
     * @param  list<float>  $indexedData
     * @param  list<int>  $sampleCounts
     * @param  list<bool>  $isSmallSample
     */
    public function __construct(
        public int $branchId,
        public string $label,
        public array $data,
        public array $indexedData,
        public array $sampleCounts,
        public array $isSmallSample,
        public float $overallDailyAverage,
    ) {}
}
