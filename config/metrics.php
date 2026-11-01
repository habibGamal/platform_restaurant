<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Metrics Cache TTL
    |--------------------------------------------------------------------------
    |
    | Default TTL (in seconds) for caching MetricsQuery results.
    | Set to 0 to disable metrics caching.
    |
    */
    'cache_ttl' => (int) env('METRICS_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Metrics Live Cache TTL
    |--------------------------------------------------------------------------
    |
    | TTL (in seconds) for live queries touching today or intra-day hourly totals.
    | A shorter TTL ensures near-real-time freshness for ongoing business days
    | while still deduplicating queries within single agent turns or page visits.
    | Set to 0 to bypass cache completely for live/today data.
    |
    */
    'cache_live_ttl' => (int) env('METRICS_CACHE_LIVE_TTL', 15),
];
