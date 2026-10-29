<?php

namespace App\Providers;

use App\Domain\Metrics\Aggregation\BranchDayRebuilder;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use Illuminate\Support\ServiceProvider;

/** Aggregation (task PF1): bind BranchDayAggregator here. */
class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BranchDayAggregator::class, BranchDayRebuilder::class);
    }

    public function boot(): void
    {
        //
    }
}
