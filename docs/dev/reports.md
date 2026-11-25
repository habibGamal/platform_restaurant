# Reports Catalog

The tenant App Panel (`/app`) exposes **10 production analytical report pages** under the **التقارير** navigation group. Every report page extends `App\Filament\App\Pages\Reports\ReportPage` and reads data through pure PHP report services under `app/Domain/Metrics/Reports/`.

> [!NOTE]
> Earlier planning documents sketched 8 additional conceptual AI report pages (`11`–`18`). Those were superseded by the interactive AI Analyst (`/assistant` + `RestaurantAnalyst`) and Menu Intelligence pages (`/app/management/*`), while unbuilt analyzers, alerts, and WhatsApp briefs are tracked under `.workspace/specs/`.

---

## Shared UI & Pagination Infrastructure

- **Global Scope Bar (`ReportScopeSelector`):** Mounted in `PanelsRenderHook::TOPBAR_END` (`AppPanelProvider`) for users with `analytics.view`, syncing selected restaurant, branches, or `BranchSet`.
- **Deferred Filter Lifecycle (`ReportPage` + `InteractsWithReportFilters`):** Draft filter changes in the form do not trigger database queries until the user clicks **Apply** (or **Reset**), which updates applied filter state, URL query parameters, and lazy widget keys.
- **In-Memory Array Tables (`ArrayTableRecords`):** Used by `BaseReportTableWidget` and `BaseReportViewWidget` for computed matrices:
  - Page sizes `[15, 30, 50, 100]` via `LengthAwarePaginator`
  - Global search (strips HTML tags across non-underscore scalar fields) and per-column search
  - Numeric-aware sorting (`sortValue()` strips `↑`, `↓`, `#`, `,`, `%` and currency suffixes before comparing floats, falling back to `strnatcasecmp`)
- **Chart Formatting (`BaseReportChartWidget`):** Uses `Filament\Support\RawJs` for Arabic/percentage tooltip formatting.

---

## Implemented Reports (01–10)

| # | Report & Slug | Page & Service | Primary Sources | Key Widgets |
|---|---|---|---|---|
| **01** | **Overview** (`/app/reports/overview`) | `Overview` / `OverviewReport` | `agg_branch_daily`, `branches` | `OverviewKpiStatsWidget` (`K06`, `K14`, `K01`, `K16`), `OverviewSalesTrendChartWidget`, `OverviewBranchTableWidget`, `OverviewProfitBridgeChartWidget`, `OverviewExpenseMixChartWidget`, `OverviewSalesByChannelChartWidget`, `OverviewDataFreshnessWidget` |
| **02** | **Sales Comparison** (`/app/reports/sales`) | `SalesComparison` / `SalesComparisonReport`, `OrderTypeSales` | `agg_branch_daily`, `wh_orders` | `SalesKpiOverviewWidget`, `OrderTypeSalesWidget` (channel breakdown before returns), `SalesKpiMatrixWidget`, `TrafficBasketScatterWidget`, `SalesTrendChartWidget` (absolute or base-100 indexed), `GrowthDecompositionTableWidget` (orders + ticket + returns effects), `SalesMixChartWidget`, `DiscountIntensityWidget` |
| **03** | **Profitability** (`/app/reports/profitability`) | `Profitability` / `ProfitabilityReport` | `agg_branch_daily`, `agg_expense_daily`, `dim_expense_types` | `ProfitabilityKpiStatsWidget` (`K09`, `K10`, `K14`, `K15`, `K19`), `ProfitabilityPlMatrixWidget` (paid vs allocated labor/rent toggle, exclude inventory variance toggle), `MarginLadderChartWidget`, `MarginLeakageChartWidget`, `MarginTrendChartWidget`, `ProfitSalesScatterChartWidget` |
| **04** | **Food Cost & Waste** (`/app/reports/food-cost`) | `FoodCostWaste` / `FoodCostWasteReport` | `agg_branch_daily`, `agg_product_daily`, `wh_wastes`, `wh_stocktakings`, `wh_purchases` | `FoodCostKpiOverviewWidget` (`K16`, `K17`, `K12`, `K13`), `FoodCostTrendChartWidget`, `FoodCostBranchMatrixWidget`, `TopWastedItemsTableWidget`, `StocktakingVarianceTableWidget`, `PurchasePriceMovementTableWidget` |
| **05** | **Product Performance** (`/app/reports/products`) | `ProductPerformance` / `ProductPerformanceReport` | `agg_product_daily`, `dim_products`, `dim_categories`, `catalog_items` | `ProductPerformanceKpiWidget`, `ProductParetoWidget` (80/20 cumulative revenue share), `ProductRankingTableWidget` (top/bottom/unsold items), `ProductCrossBranchMatrixWidget`, `ProductCategoryPerformanceWidget` |
| **06** | **Shifts & Weekdays** (`/app/reports/shifts`) | `ShiftsWeekdays` / `ShiftsWeekdaysReport` | `agg_shift`, `wh_shifts`, `agg_branch_hourly`, `dim_staff` | `ShiftsKpiOverviewWidget`, `HourlyHeatmapWidget` (24x7 matrix), `PeakHoursChartWidget`, `WeekdayProfileChartWidget`, `ShiftSlotsTableWidget`, `CashierSummaryTableWidget`, `ShiftsTableWidget` (`K25` deficits) |
| **07** | **Branch Ranking** (`/app/reports/ranking`) | `BranchRanking` / `BranchRankingReport` | `agg_branch_daily`, `scoring_profiles`, `targets` | `RankingPodiumWidget`, `RankingLeagueTableWidget` (with rank deltas & excluded incomplete branches), `PerKpiLeadersWidget`, `RankHistoryChartWidget` |
| **08** | **Targets vs Actual** (`/app/reports/targets`) | `TargetsVsActual` / `TargetsVsActualReport` | `targets`, `target_daily`, `agg_branch_daily` | `TargetsGaugeOverviewWidget`, `TargetsBurnUpChartWidget`, `TargetsBranchTableWidget` (MTD, projected month-end, required run-rate), `TargetsHistoryWidget` |
| **09** | **Expenses & Purchases** (`/app/reports/expenses`) | `ExpensesPurchases` / `ExpensesPurchasesReport` | `wh_expenses`, `agg_expense_daily`, `wh_purchases`, `agg_purchase_daily`, `dim_suppliers`, `dim_expense_types` | `ExpensesPurchasesKpiStatsWidget`, `BudgetVsActualWidget` (vs `avg_month_rate`), `ExpenseListTableWidget` (DB-paginated), `CategoryBranchMatrixWidget`, `ExpensesVsSalesGrowthWidget`, `PurchasesPerBranchWidget`, `SupplierSummaryTableWidget`, `PurchasesVsCogsWidget` |
| **10** | **Data Health & Sync** (`/app/reports/data-health`) | `DataHealth` / `DataHealthReport` | `branches`, `ingest_batches`, `ingest_rejections`, `reconciliation_issues`, `wh_orders`, `agg_branch_daily` | `DataHealthOverviewWidget`, `BranchStatusTableWidget` (`online`/`delayed`/`offline`), `CompletenessCalendarWidget`, `ReconciliationIssuesTableWidget` (with `resync` trigger), `IngestRejectionsTableWidget`, `ProfitDriftTableWidget` (>1% `profit_pos` vs `K09` drift) |
