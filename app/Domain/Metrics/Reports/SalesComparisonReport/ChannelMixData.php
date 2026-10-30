<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports\SalesComparisonReport;

final readonly class ChannelMixData
{
    /**
     * @param  list<string>  $branches
     * @param  list<string>  $channels
     * @param  array<string, string>  $channelLabels
     * @param  array<string, list<?float>>  $channelSeries
     * @param  list<string>  $paymentMethods
     * @param  array<string, string>  $paymentLabels
     * @param  array<string, list<?float>>  $paymentSeries
     */
    public function __construct(
        public array $branches,
        public array $channels,
        public array $channelLabels,
        public array $channelSeries,
        public array $paymentMethods,
        public array $paymentLabels,
        public array $paymentSeries,
    ) {}
}
