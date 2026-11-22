<?php

return [
    'intelligence' => [
        'months_back' => 3,
        'trusted_confidence' => 0.70,
        'rejudge_days' => 30,
        'classification_batch' => 60,
        'min_days_on_sale' => 14,
        'min_baskets' => 200,
        'min_support_orders' => 5,
        'min_support_ratio' => 0.002,
        'max_itemset_size' => 4,
        'combo_overlap_threshold' => 0.80,
        'combo_heroes_per_template' => 20,
        'combos_per_hero' => 3,
        'build_delay_minutes' => 15,
        'affinity_chunk_orders' => 2000,
    ],
    'seasons' => [
        'winter_months' => [11, 12, 1, 2],
        'summer_months' => [6, 7, 8],
        'ramadan' => [['2026-02-18', '2026-03-19'], ['2027-02-08', '2027-03-09']],
        'fasting' => [],
    ],
    'strategies' => [
        'push_next_month' => ['quadrants' => null, 'weights' => ['unit_margin' => 0.30, 'headroom' => 0.25, 'momentum' => 0.20, 'season_fit' => 0.15, 'repeat' => 0.10]],
        'protect' => ['quadrants' => ['stars'], 'weights' => ['popularity' => 0.50, 'unit_margin' => 0.50]],
        'reprice' => ['quadrants' => ['workhorses'], 'weights' => ['popularity' => 0.60, 'low_margin_pct' => 0.40]],
        'fix_or_drop' => ['quadrants' => ['weak'], 'weights' => ['low_popularity' => 0.40, 'low_unit_margin' => 0.30, 'low_momentum' => 0.30]],
    ],
    'templates' => [
        'solo_meal' => ['hero_tier' => 'single', 'slots' => [['roles' => ['side'], 'qty' => 1], ['roles' => ['drink'], 'qty' => 1]]],
        'sharing_box' => ['hero_tier' => 'sharing', 'slots' => [['roles' => ['side'], 'qty' => 1], ['roles' => ['drink'], 'qty' => 'serves_n']]],
        'family_feast' => ['hero_tier' => 'family', 'slots' => [['roles' => ['drink'], 'qty' => 'serves_n'], ['roles' => ['side'], 'prefer_classes' => ['soup'], 'qty' => 1], ['roles' => ['dessert'], 'qty' => 1, 'optional' => true]]],
    ],
];
