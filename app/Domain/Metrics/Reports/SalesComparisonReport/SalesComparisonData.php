<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\SalesComparisonReport;

final readonly class SalesComparisonData
{
    /**
     * @param  array<string, array{value: float|int|null, comparison: float|int|null, sparkline?: list<float|int>}>  $overview
     * @param  list<SalesKpiMatrixRow>  $matrix
     * @param  list<TrafficScatterPoint>  $scatter
     * @param  list<GrowthDecompositionRow>  $growthDecomposition
     * @param  list<DiscountIntensityRow>  $discountIntensity
     */
    public function __construct(
        public array $overview,
        public array $matrix,
        public array $scatter,
        public ?float $medianOrdersPerDay,
        public ?float $medianAvgTicket,
        public SalesTrendData $trendAbsolute,
        public SalesTrendData $trendIndexed,
        public array $growthDecomposition,
        public ChannelMixData $channelMix,
        public array $discountIntensity,
    ) {}
}
