<?php

return [
    /*
    |--------------------------------------------------------------------------
    | USD to EGP Exchange Rate
    |--------------------------------------------------------------------------
    |
    | The conversion rate used to translate AI provider costs in USD to EGP.
    |
    */
    'usd_to_egp' => (float) env('AI_USD_TO_EGP', 50.0),

    /*
    |--------------------------------------------------------------------------
    | Default Monthly Budgets Per Plan (in EGP)
    |--------------------------------------------------------------------------
    |
    | Default AI spend budget per organization based on subscription plan.
    |
    */
    'default_monthly_budgets' => [
        'basic' => (float) env('AI_DEFAULT_BUDGET_BASIC_EGP', 0.0),
        'pro' => (float) env('AI_DEFAULT_BUDGET_PRO_EGP', 1500.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Limits on message frequency and concurrent agent execution.
    |
    */
    'rate_limits' => [
        'messages_per_minute_per_user' => (int) env('AI_RATE_LIMIT_USER_MESSAGES_PER_MIN', 20),
        'concurrent_runs_per_org' => (int) env('AI_RATE_LIMIT_ORG_CONCURRENT_RUNS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Run Token Cap
    |--------------------------------------------------------------------------
    |
    | Maximum input tokens allowed per execution run to prevent runaway costs.
    |
    */
    'per_run_input_token_cap' => (int) env('AI_PER_RUN_INPUT_TOKEN_CAP', 120000),
];
