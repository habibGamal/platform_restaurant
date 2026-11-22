<?php

declare(strict_types=1);

return [
    'react_ui' => (bool) env('ASSISTANT_REACT_UI', true),

    'ui_tools' => [
        'rank_items',
        'get_combo_candidates',
        'get_item_card',
        'get_affinities',
        'get_kpi_series',
    ],

    'stream' => [
        'poll_ms' => 250,
        'max_seconds' => 960,
        'queue_notice_seconds' => 60,
        'queue_give_up_seconds' => (int) env('ASSISTANT_QUEUE_RETRY_AFTER', 960),
    ],
];
