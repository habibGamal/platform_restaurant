<?php

declare(strict_types=1);

/**
 * Assistant Evaluation Dataset v1: specialist routing and marketing guardrails.
 *
 * Same case shape as v0, plus:
 *  - fixture: 'two_branches' (TwoBranchesTwoWeeks, 2026-10-03 .. 2026-10-16) or
 *             'menu' (MenuFixture, 2026-08-18 .. 2026-10-16, invented items with owner-set roles)
 *  - rules:   graded against the run transcript (see EvalRunner):
 *             no_delegation | delegates:<agent> | focus_excludes_roles:<role>,... |
 *             combos_one_main_above_floor | grounded
 *
 * Marketing cases run on the menu fixture, whose highest-quantity item is a cheap drink that is
 * never ordered alone: it must never be put under «ركز على» nor anchor a combo.
 */
$marketingRules = [
    'delegates:marketing_specialist',
    'focus_excludes_roles:drink,addon,adjustment,raw',
    'combos_one_main_above_floor',
    'grounded',
];

return [
    // -------------------------------------------------------------------------
    // 1. Marketing (4 cases)
    // -------------------------------------------------------------------------
    [
        'id' => 'mkt_focus_next_month',
        'category' => 'marketing',
        'persona' => 'owner',
        'question' => 'أركز على إيه في التسويق الشهر الجاي؟ استخدم بيانات الفترة من 17 سبتمبر إلى 16 أكتوبر 2026.',
        'expected_tools' => ['marketing_specialist', 'menu_engineering'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'menu',
        'rules' => $marketingRules,
    ],
    [
        'id' => 'mkt_top_seller_campaign',
        'category' => 'marketing',
        'persona' => 'owner',
        'question' => 'إيه أكتر صنف بيتطلب عندي من 17 سبتمبر إلى 16 أكتوبر 2026؟ عايز أعمل عليه حملة إعلانية كبيرة.',
        'expected_tools' => ['marketing_specialist', 'menu_engineering'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'menu',
        'rules' => $marketingRules,
    ],
    [
        'id' => 'mkt_combo_offer',
        'category' => 'marketing',
        'persona' => 'owner',
        'question' => 'اقترح لي كومبو أو عرض يزود المبيعات من غير ما يضر هامش الربح، على بيانات الفترة من 17 سبتمبر إلى 16 أكتوبر 2026.',
        'expected_tools' => ['marketing_specialist', 'combo_builder'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'menu',
        'rules' => $marketingRules,
    ],
    [
        'id' => 'mkt_rising_items_branch_b',
        'category' => 'marketing',
        'persona' => 'owner',
        'question' => 'إيه الأصناف اللي طلبها بيزيد في فرع B ومحتاجة دعم تسويقي؟ الفترة من 17 سبتمبر إلى 16 أكتوبر 2026.',
        'expected_tools' => ['marketing_specialist', 'item_trends'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'menu',
        'rules' => $marketingRules,
    ],

    // -------------------------------------------------------------------------
    // 2. Finance (3 cases)
    // -------------------------------------------------------------------------
    [
        'id' => 'fin_sales_forecast_next_month',
        'category' => 'finance',
        'persona' => 'owner',
        'question' => 'المبيعات هتبقى كام الشهر الجاي؟ البيانات المتاحة لحد 16 أكتوبر 2026.',
        'expected_tools' => ['finance_specialist', 'sales_forecast'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'menu',
        'rules' => ['delegates:finance_specialist', 'grounded'],
    ],
    [
        'id' => 'fin_profit_gap_between_branches',
        'category' => 'finance',
        'persona' => 'owner',
        'question' => 'ليه ربح فرع أ أعلى من فرع ب في الفترة من 3 إلى 16 أكتوبر 2026؟ وإيه اللي بيأثر على هامش الربح؟',
        'expected_tools' => ['finance_specialist', 'profitability'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'two_branches',
        'rules' => ['delegates:finance_specialist', 'grounded'],
    ],
    [
        'id' => 'fin_food_cost_check',
        'category' => 'finance',
        'persona' => 'owner',
        'question' => 'تكلفة الأكل عندي مظبوطة ولا عالية في الفترة من 3 إلى 16 أكتوبر 2026؟ وإيه اللي محتاج أراجعه؟',
        'expected_tools' => ['finance_specialist', 'food_cost_waste'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'two_branches',
        'rules' => ['delegates:finance_specialist', 'grounded'],
    ],

    // -------------------------------------------------------------------------
    // 3. Operations (3 cases)
    // -------------------------------------------------------------------------
    [
        'id' => 'ops_staffing_peak_hours',
        'category' => 'operations',
        'persona' => 'owner',
        'question' => 'أزود الشيفت في أنهي ساعات؟ استخدم بيانات الفترة من 17 سبتمبر إلى 16 أكتوبر 2026.',
        'expected_tools' => ['operations_specialist', 'peak_hours'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'menu',
        'rules' => ['delegates:operations_specialist', 'grounded'],
    ],
    [
        'id' => 'ops_weakest_weekday',
        'category' => 'operations',
        'persona' => 'owner',
        'question' => 'أنهي يوم في الأسبوع شغلنا فيه أضعف ومحتاج أقلل فيه العمالة؟ الفترة من 17 سبتمبر إلى 16 أكتوبر 2026.',
        'expected_tools' => ['operations_specialist', 'shifts_weekdays'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'menu',
        'rules' => ['delegates:operations_specialist', 'grounded'],
    ],
    [
        'id' => 'ops_branch_needs_attention',
        'category' => 'operations',
        'persona' => 'owner',
        'question' => 'أنهي فرع محتاج اهتمام تشغيلي أكتر في الفترة من 3 إلى 16 أكتوبر 2026 وليه؟',
        'expected_tools' => ['operations_specialist', 'branch_ranking'],
        'expected_numbers' => [],
        'must_refuse' => false,
        'fixture' => 'two_branches',
        'rules' => ['delegates:operations_specialist', 'grounded'],
    ],

    // -------------------------------------------------------------------------
    // 4. Simple KPIs answered directly (2 cases)
    // -------------------------------------------------------------------------
    [
        'id' => 'kpi_direct_sales_branch_a',
        'category' => 'kpis',
        'persona' => 'manager_a',
        'question' => 'ما هو إجمالي صافي المبيعات لفرع أ في الفترة من 3 إلى 16 أكتوبر 2026؟',
        'expected_tools' => ['get_kpis'],
        'expected_numbers' => [12780],
        'must_refuse' => false,
        'fixture' => 'two_branches',
        'rules' => ['no_delegation', 'grounded'],
    ],
    [
        'id' => 'kpi_direct_org_sales',
        'category' => 'kpis',
        'persona' => 'owner',
        'question' => 'ما هو إجمالي مبيعات كل الفروع معاً في الفترة من 3 إلى 16 أكتوبر 2026؟',
        'expected_tools' => ['get_kpis'],
        'expected_numbers' => [16940],
        'must_refuse' => false,
        'fixture' => 'two_branches',
        'rules' => ['no_delegation', 'grounded'],
    ],
];
