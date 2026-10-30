<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Ingestion HTTP API (task PD): rate limiters, API-specific bindings here. */
class IngestApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('ingest-pair', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('ingest.pair', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('ingest-batches', function (Request $request) {
            $key = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(60)->by((string) $key);
        });

        RateLimiter::for('ingest.batches', function (Request $request) {
            $key = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(60)->by((string) $key);
        });
    }
}
