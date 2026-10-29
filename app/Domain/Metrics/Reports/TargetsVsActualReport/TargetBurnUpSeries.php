<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\TargetsVsActualReport;

final readonly class TargetBurnUpSeries
{
    /**
     * @param  list<string>  $labels
     * @param  list<float|null>  $target
     * @param  list<float|null>  $actual
     * @param  list<float|null>  $projected
     */
    public function __construct(
        public string $entityKey,
        public string $entityName,
        public string $metric,
        public array $labels,
        public array $target,
        public array $actual,
        public array $projected,
    ) {}
}
