<?php
$sp = $argv[1];
$tpl = file_get_contents("$sp/briefs/report-template.txt");
$kit = "UI KIT (landed — app/Filament/App/**, app/Support/**):\n".file_get_contents("$sp/kit-api.md");
$metrics = "METRICS LAYER (landed — app/Domain/Metrics/**, app/Domain/Targets/**). Build a MetricsQuery in widgets/services with MetricsQuery::for(auth()->user(), \$this->reportFilters()).\n".file_get_contents("$sp/metrics-api.md");

$whNote = "- This report needs warehouse/dimension detail (wh_* / dim_* / control tables) that MetricsQuery::scoped() does not allow. You may query those tables directly in your service ONLY with ->whereIn('branch_id', \$query->branchIds()) and a business_date/period filter — never without the branch filter.";

$reports = [
    ['01', 'لوحة التحكم الرئيسية', '01-group-overview-dashboard.md', 'Overview', 'reports/overview', 'OverviewReport', 'Overview', 'overview', 'Overview', 'platform_test_1',
        "- Target % column: use App\\Domain\\Targets\\TargetAchievement::for(\$query, 'net_sales', today). Data freshness strip: branches.last_seen_at / last_batch_at / last_heartbeat_at (scoped to \$query->branchIds()).\n- Today view: compare with hourlyTotals() up to the current hour as the spec says.", ''],
    ['02', 'مقارنة المبيعات', '02-sales-comparison.md', 'SalesComparison', 'reports/sales', 'SalesComparisonReport', 'SalesComparison', 'sales-comparison', 'SalesComparison', 'platform_test_2', '', ''],
    ['03', 'الأرباح والهوامش', '03-profitability-comparison.md', 'Profitability', 'reports/profitability', 'ProfitabilityReport', 'Profitability', 'profitability', 'Profitability', 'platform_test_4',
        "- Labor basis toggle (paid / allocated): use App\\Domain\\Metrics\\AllocatedCost::labor()/rent() for the allocated basis; add the toggle as a widget-level filter or page action local to this report.", ''],
    ['04', 'تكلفة الخامات والهالك', '04-food-cost-and-waste.md', 'FoodCostWaste', 'reports/food-cost', 'FoodCostWasteReport', 'FoodCostWaste', 'food-cost-waste', 'FoodCostWaste', 'platform_test_5',
        $whNote."\n- Food cost band defaults (28–35 %) as constants in your service until an org setting exists.", ''],
    ['05', 'أداء الأصناف', '05-product-performance.md', 'ProductPerformance', 'reports/products', 'ProductPerformanceReport', 'ProductPerformance', 'product-performance', 'ProductPerformance', 'platform_test_6',
        $whNote." (dim_products, dim_categories, catalog_items for names and cross-branch identity).", ''],
    ['06', 'الورديات وأيام الأسبوع', '06-shifts-weekdays-hours.md', 'ShiftsWeekdays', 'reports/shifts', 'ShiftsWeekdaysReport', 'ShiftsWeekdays', 'shifts-weekdays', 'ShiftsWeekdays', 'platform_test_7',
        $whNote." (dim_staff for cashier names, calendar_events for exclusion).", ''],
    ['07', 'ترتيب الفروع', '07-branch-ranking.md', 'BranchRanking', 'reports/ranking', 'BranchRankingReport', 'BranchRanking', 'branch-ranking', 'BranchRanking', 'platform_test_8',
        "- Scoring profile: read scoring_profiles (weights json) for the org, default profile from the spec when none. Also build a small management page app/Filament/App/Pages/Management/ScoringProfilePage.php (navigation group 'الإدارة', permission users.manage) to edit the weights (must sum to 100, Arabic validation message).\n- Target achievement KPI: App\\Domain\\Targets\\TargetAchievement::for(\$query, 'net_sales', today).\n- Excluded branches: App\\Domain\\Metrics\\Completeness.", ', app/Filament/App/Pages/Management/ScoringProfilePage.php, tests/Feature/App/Management/ScoringProfilePageTest.php'],
    ['08', 'المستهدف مقابل المحقق', '08-targets-vs-actual.md', 'TargetsVsActual', 'reports/targets', 'TargetsVsActualReport', 'TargetsVsActual', 'targets-vs-actual', 'TargetsVsActual', 'platform_test_9',
        "- Use App\\Domain\\Targets\\TargetAchievement and target_daily (via TargetDistributor) — do not reimplement projection logic. Burn-up chart: cumulative target_daily vs cumulative actual. Tests must create Target rows for fixture branches (the observer persists target_daily).", ''],
    ['09', 'المصروفات والمشتريات', '09-expenses-and-purchases.md', 'ExpensesPurchases', 'reports/expenses', 'ExpensesPurchasesReport', 'ExpensesPurchases', 'expenses-purchases', 'ExpensesPurchases', 'platform_test_10',
        $whNote." (wh_expenses list, dim_expense_types incl. category_override and avg_month_rate, dim_suppliers). Link the 'unclassified expense types' banner to the existing ExpenseCategoryMappingResource.", ''],
    ['10', 'حالة البيانات والاتصال', '10-branch-sync-health.md', 'DataHealth', 'reports/data-health', 'DataHealthReport', 'DataHealth', 'data-health', 'DataHealth', 'platform_test_11',
        $whNote." Control tables: branches (heartbeat fields), ingest_batches, ingest_rejections, reconciliation_issues, branch_commands, wh_day_summaries. The 'طلب إعادة مزامنة' action creates a branch_commands row {type:'resync', payload:{from,to}} (permission branches.manage). Completeness calendar via App\\Domain\\Metrics\\Completeness.\n- Skip the operator-panel (all orgs) variant; tenant page only.", ''],
];

$outputRules = "\n<output_discipline>\nKeep tool output small (it is re-sent on every model call): run only your own test folder; when a run fails, re-run just the failing test with --filter; pipe long outputs through tail/Select-Object -Last 40; run phpstan only on your own paths; do not cat whole vendor files — read only the specific method you need.\n</output_discipline>\n";

foreach ($reports as [$num, $title, $spec, $page, $slug, $service, $widgetDir, $viewDir, $testName, $db, $extra, $extraFiles]) {
    $b = strtr($tpl, [
        '{{NUM}}' => $num, '{{TITLE}}' => $title, '{{SPEC}}' => $spec, '{{PAGE}}' => $page, '{{SLUG}}' => $slug,
        '{{SERVICE}}' => $service, '{{SERVICE_DIR}}' => $service, '{{WIDGET_DIR}}' => $widgetDir, '{{VIEW_DIR}}' => $viewDir,
        '{{TESTNAME}}' => $testName, '{{DB}}' => $db, '{{EXTRA}}' => $extra, '{{EXTRA_FILES}}' => $extraFiles,
        '{{KIT_API}}' => $kit, '{{METRICS_API}}' => $metrics,
    ]);
    $b = str_replace('<action_safety>', $outputRules.'<action_safety>', $b);
    if (str_contains($b, '{{')) { fwrite(STDERR, "unfilled placeholder in $num\n"); exit(1); }
    file_put_contents("$sp/briefs/report-$num.txt", $b);
    echo "report-$num ", strlen($b), " bytes\n";
}
