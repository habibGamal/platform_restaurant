# 08. Comprehensive Reports Catalog & Table Pagination Engine

This document provides a detailed catalog of the 10 production report pages, their domain calculators, query sources, filter controls, widgets, chart visual structures, and the table pagination engine (`ArrayTableRecords`).

---

## 1. Table Pagination & Search Engine ([`ArrayTableRecords`](../../app/Filament/App/Widgets/Reports/ArrayTableRecords.php))

Unlike typical Filament tables bound directly to Eloquent builders, report widgets in `platform-restaurants` often calculate complex aggregated matrices in PHP memory (DTOs, cross-branch matrices, percentile league tables).

[`BaseReportTableWidget`](../../app/Filament/App/Widgets/Reports/BaseReportTableWidget.php) and [`BaseReportViewWidget`](../../app/Filament/App/Widgets/Reports/BaseReportViewWidget.php) integrate [`ArrayTableRecords::paginate()`](../../app/Filament/App/Widgets/Reports/ArrayTableRecords.php) to provide full interactive table features:

- **Pagination**: Supports page size intervals `[15, 30, 50, 100]` with `LengthAwarePaginator`.
- **Global Search**: Strips HTML tags (`<[^>]*>`) and searches scalar row fields other than underscore-prefixed fields; it is not limited to visible columns.
- **Individual Column Search**: Per-column search input fields (`searchable(isIndividual: true)`).
- **Intelligent Sorting**:
  - Automatically identifies formatted currency and numeric values (e.g. `+1,250.00 EGP`, `34.5%`, `▲ +2`).
  - Strips symbols (`↑`, `↓`, `#`, `,`, `%`) and converts to raw float for true mathematical ordering via [`ArrayTableRecords::sortValue()`](../../app/Filament/App/Widgets/Reports/ArrayTableRecords.php).
  - Falls back to natural string sorting (`strnatcasecmp`) for text columns.
- Array-backed tables build and filter the complete result in PHP before slicing; pagination limits displayed rows rather than database work. The expense drill-down uses database pagination. Custom Blade tables and management-resource tables do not all share these controls.
- **Column Toggle**: Allows users to hide/show specific KPI columns (`toggleable()`).

---

## 2. The 10 Implemented Reports

```
Reports Navigation Group (/app)
  |-- 01. Overview (/app/reports/overview)
  |-- 02. Sales Comparison (/app/reports/sales)
  |-- 03. Profitability (/app/reports/profitability)
  |-- 04. Product Performance (/app/reports/products)
  |-- 05. Food Cost & Waste (/app/reports/food-cost)
  |-- 06. Expenses & Purchases (/app/reports/expenses)
  |-- 07. Shifts & Weekdays (/app/reports/shifts)
  |-- 08. Branch Ranking (/app/reports/ranking)
  |-- 09. Targets vs Actual (/app/reports/targets)
  `-- 10. Data Health & Sync (/app/reports/data-health)
```

---

### Report 01: Overview (`/app/reports/overview`)
- **Page Class**: [`App\Filament\App\Pages\Reports\Overview`](../../app/Filament/App/Pages/Reports/Overview.php)
- **Domain Calculator**: [`OverviewReport`](../../app/Domain/Metrics/Reports/OverviewReport.php)
- **Primary Sources**: `agg_branch_daily`, `branches`
- **Widgets Included**:
  1. `OverviewKpiStatsWidget`: 4 headline cards: Net Sales ($K06$), Net Profit ($K14$), Orders ($K01$), and Food Cost % ($K16$) with growth deltas.
  2. `OverviewSalesTrendChartWidget`: Net sales trend line chart across the period's buckets.
  3. `OverviewBranchTableWidget`: Summary league table per branch: Orders, Sales, Profit, Margin, and Completeness status.
  4. `OverviewProfitBridgeChartWidget`: Waterfall / bridge chart showing progression: $\text{Gross Sales} \to \text{Discounts} \to \text{Returns} \to \text{COGS} \to \text{Gross Profit} \to \text{Expenses} \to \text{Waste} \to \text{Net Profit}$.
  5. `OverviewExpenseMixChartWidget`: Doughnut chart of operating expenses (Labor, Rent, Utilities, Supplies, Other).
  6. `OverviewSalesByChannelChartWidget`: Horizontal bar chart breaking down sales by channel (`dine_in`, `takeaway`, `delivery`, `talabat`, etc.).
  7. `OverviewDataFreshnessWidget`: Telemetry badge showing sync freshness and last batch arrival.

---

### Report 02: Sales Comparison (`/app/reports/sales`)
- **Page Class**: [`App\Filament\App\Pages\Reports\SalesComparison`](../../app/Filament/App/Pages/Reports/SalesComparison.php)
- **Domain Calculator**: [`SalesComparisonReport`](../../app/Domain/Metrics/Reports/SalesComparisonReport.php)
- **Primary Sources**: `agg_branch_daily`, `wh_orders` (for channel breakdowns)
- **Specific Filters**: Channel select filter (`dine_in`, `takeaway`, `delivery`).
- **Widgets Included**:
  1. `SalesKpiOverviewWidget`: Headline comparison stats for Sales, Orders, Average Ticket, and Discounts.
  2. `OrderTypeSalesWidget` (via [`OrderTypeSales`](../../app/Domain/Metrics/Reports/OrderTypeSales.php)): Cards and summary table breaking down completed orders before returns by order type (`dine_in`, `takeaway`, `delivery`, `drive_thru`, `online`, `web_delivery`, `web_takeaway`).
  3. `SalesKpiMatrixWidget`: Side-by-side comparative matrix per branch and Group Average row ("متوسط المجموعة"). Displays current vs comparison for Sales, Orders, Average Ticket, Discounts, Cancel Rate, and Return Rate.
  4. `TrafficBasketScatterWidget`: Scatter plot plotting **Orders per Day (X-axis)** against **Average Ticket (Y-axis)** with quadrant crosshairs centered on group medians.
  5. `SalesTrendChartWidget`: Multi-branch sales trend lines, toggleable between **Absolute EGP** and **Indexed Growth (Base 100)**.
  6. `GrowthDecompositionTableWidget`: Variance decomposition table per branch:
     $$\Delta \text{Net Sales} = \text{Orders Effect} + \text{Ticket Effect}$$
     $$\text{Orders Effect} = (O_1 - O_0) \times T_0$$
     $$\text{Ticket Effect} = (T_1 - T_0) \times O_1$$
     $$\text{Returns Effect} = R_0 - R_1$$
     $$\Delta \text{Sales After Returns} = S_1 - S_0$$
  7. `SalesMixChartWidget`: Stacked 100% bar chart showing the percentage share of each channel per branch.
  8. `DiscountIntensityWidget`: Discount rate as a percentage of gross sales.

---

### Report 03: Profitability Comparison (`/app/reports/profitability`)
- **Page Class**: [`App\Filament\App\Pages\Reports\Profitability`](../../app/Filament/App/Pages/Reports/Profitability.php)
- **Domain Calculator**: [`ProfitabilityReport`](../../app/Domain/Metrics/Reports/ProfitabilityReport.php)
- **Primary Sources**: `agg_branch_daily`, `agg_expense_daily`, `dim_expense_types`
- **Widgets Included**:
  1. `ProfitabilityKpiStatsWidget`: Gross Profit ($K09$), Gross Margin ($K10$), Net Profit ($K14$), Net Margin ($K15$), and Prime Cost % ($K19$).
  2. `ProfitabilityPlMatrixWidget`: Full P&L matrix per branch: Net Sales, COGS, Gross Profit, Labor (allocated), Rent (allocated), Waste, Inventory Variance, Operating Expenses, Net Profit, and Net Margin %.
  3. `MarginLadderChartWidget`: Margin ladder visualization from Gross Margin down to Net Margin.
  4. `MarginLeakageChartWidget`: Bar chart identifying margin leakage drivers (Discounts %, Waste %, Labor %, Deficits %).
  5. `MarginTrendChartWidget`: Weekly/monthly margin % trajectories over time.
  6. `ProfitSalesScatterChartWidget`: Scatter plot of **Net Sales (X-axis)** vs **Net Margin % (Y-axis)**.

---

### Report 04: Product Performance (`/app/reports/products`)
- **Page Class**: [`App\Filament\App\Pages\Reports\ProductPerformance`](../../app/Filament/App/Pages/Reports/ProductPerformance.php)
- **Domain Calculator**: [`ProductPerformanceReport`](../../app/Domain/Metrics/Reports/ProductPerformanceReport.php)
- **Primary Sources**: `agg_product_daily`, `dim_products`, `dim_categories`, `catalog_items`
- **Specific Filters**: Category UUID dropdown, rank metric (`sales`, `qty`, `profit`), `includeLegacy` toggle.
- **Widgets Included**:
  1. `ProductPerformanceKpiWidget`: Total Products Sold, Quantity Sold, Net Sales, Gross Profit, Margin %, Unsold Manufactured Items count.
  2. `ProductParetoWidget`: Pareto 80/20 analysis:
     - Ranks items descending by sales.
     - Calculates individual share % and cumulative share %.
     - Highlights items generating the first 80% of revenue.
     - Reports exact ratio: e.g., "14 items (18.2% of menu) generate 80% of revenue".
  3. `ProductRankingTableWidget`: Top 50 items, Bottom 50 items, and Unsold Items tables with daily sales sparklines and group rankings.
  4. `ProductCrossBranchMatrixWidget`: Heatmap matrix showing sales/quantity per day for each product across all active branches.
  5. `ProductCategoryPerformanceWidget`: Category rollups showing category sales share and margin %.

---

### Report 05: Food Cost & Waste (`/app/reports/food-cost`)
- **Page Class**: [`App\Filament\App\Pages\Reports\FoodCostWaste`](../../app/Filament/App/Pages/Reports/FoodCostWaste.php)
- **Domain Calculator**: [`FoodCostWasteReport`](../../app/Domain/Metrics/Reports/FoodCostWasteReport.php)
- **Primary Sources**: `agg_branch_daily`, `agg_product_daily`, `wh_wastes`, `wh_waste_items`, `wh_stocktakings`, `wh_purchases`, `wh_purchase_items`
- **Widgets Included**:
  1. `FoodCostKpiOverviewWidget`: Food Cost % ($K16$), Waste % ($K17$), Total Waste Cost ($K12$), Stocktaking Variance Cost ($K13$).
  2. `FoodCostTrendChartWidget`: Dual-axis line chart tracking Food Cost % and Waste % across period buckets.
  3. `FoodCostBranchMatrixWidget`: Cross-branch comparative table of material costs and variance.
  4. `TopWastedItemsTableWidget`: Pareto list of top 20 wasted raw materials/products by monetary loss.
  5. `StocktakingVarianceTableWidget`: Inventory count discrepancies: Theoretical vs Physical count, unit variance, and monetary cost impact.
  6. `PurchasePriceMovementTableWidget`: Identifies supplier price increases/decreases across successive purchase invoices.

---

### Report 06: Expenses & Purchases (`/app/reports/expenses`)
- **Page Class**: [`App\Filament\App\Pages\Reports\ExpensesPurchases`](../../app/Filament/App/Pages/Reports/ExpensesPurchases.php)
- **Domain Calculator**: [`ExpensesPurchasesReport`](../../app/Domain/Metrics/Reports/ExpensesPurchasesReport.php)
- **Primary Sources**: `wh_expenses`, `agg_expense_daily`, `wh_purchases`, `agg_purchase_daily`, `dim_suppliers`, `dim_expense_types`
- **Widgets Included**:
  1. `ExpensesPurchasesKpiStatsWidget`: Total Expenses ($K11$), Net Purchases ($K20$), Purchases-to-Sales Ratio.
  2. `BudgetVsActualWidget`: Compares actual monthly expenses against `dim_expense_types.avg_month_rate` budget rates.
  3. `ExpenseListTableWidget`: Paginated and searchable ledger of individual direct expenses with notes, shifts, and categories.
  4. `SupplierSummaryTableWidget`: Supplier invoice volumes, return totals, and invoice count.
  5. `PurchasesVsCogsWidget`: Evaluates inbound inventory purchasing versus depleted COGS to detect inventory build-up or depletion.
  6. `CategoryBranchMatrixWidget`: Branch-by-category expense distribution.
  7. `ExpensesVsSalesGrowthWidget`: Scatter plot correlating expense growth against sales growth.

---

### Report 07: Shifts & Weekdays (`/app/reports/shifts`)
- **Page Class**: [`App\Filament\App\Pages\Reports\ShiftsWeekdays`](../../app/Filament/App/Pages/Reports/ShiftsWeekdays.php)
- **Domain Calculator**: [`ShiftsWeekdaysReport`](../../app/Domain/Metrics/Reports/ShiftsWeekdaysReport.php)
- **Primary Sources**: `agg_shift`, `wh_shifts`, `agg_branch_hourly`, `dim_staff`
- **Widgets Included**:
  1. `ShiftsKpiOverviewWidget`: Completed Shifts count, Average Shift Duration, Net Sales per Shift, Total Cash Deficit ($K25$).
  2. `HourlyHeatmapWidget`: 24x7 matrix (Days of Week vs Business Hours 00:00 to 23:00) colored by order volume or sales.
  3. `PeakHoursChartWidget`: Bar chart identifying top sales and order intake hours.
  4. `WeekdayProfileChartWidget`: Compares average performance by weekday (Saturday through Friday).
  5. `CashierSummaryTableWidget`: Cashier scorecard: Orders processed, Sales, Average Ticket, Cancelled Orders count, and Cash Shortages.
  6. `ShiftsTableWidget`: Detailed shift log with start cash, end cash, declared cash, and shortage/surplus calculation.

---

### Report 08: Branch Ranking (`/app/reports/ranking`)
- **Page Class**: [`App\Filament\App\Pages\Reports\BranchRanking`](../../app/Filament/App/Pages/Reports/BranchRanking.php)
- **Domain Calculator**: [`BranchRankingReport`](../../app/Domain/Metrics/Reports/BranchRankingReport.php)
- **Primary Sources**: `agg_branch_daily`, `scoring_profiles`, `targets`, `branches`
- **Widgets Included**:
  1. `RankingPodiumWidget`: Visual gold/silver/bronze podium for Top 3 branches.
  2. `RankingLeagueTableWidget`: Complete ranking league table with total score, rank deltas ($\blacktriangle / \blacktriangledown$), KPI leader badges, and excluded branch diagnostics.
  3. `PerKpiLeadersWidget`: Leader cards showing top-performing branch for each of the 7 evaluated KPIs.
  4. `RankHistoryChartWidget`: Historical ranking positions over preceding periods.

*(See Document 09 for detailed scoring math, percentile scaling, and weight redistribution).*

---

### Report 09: Targets vs Actual (`/app/reports/targets`)
- **Page Class**: [`App\Filament\App\Pages\Reports\TargetsVsActual`](../../app/Filament/App/Pages/Reports/TargetsVsActual.php)
- **Domain Calculator**: [`TargetsVsActualReport`](../../app/Domain/Metrics/Reports/TargetsVsActualReport.php)
- **Primary Sources**: `targets`, `target_daily`, `agg_branch_daily`
- **Widgets Included**:
  1. `TargetsGaugeOverviewWidget`: Semi-radial gauge displaying month-to-date achievement percentage.
  2. `TargetsBurnUpChartWidget`: Daily cumulative target line vs actual cumulative sales line.
  3. `TargetsBranchTableWidget`: Branch target status table: Target Month, Target To-Date, Actual To-Date, Achievement %, Projected Month-End Total, Required Daily Run-rate for remaining days.
  4. `TargetsHistoryWidget`: Trailing month achievement trends.

---

### Report 10: Data Health & Sync (`/app/reports/data-health`)
- **Page Class**: [`App\Filament\App\Pages\Reports\DataHealth`](../../app/Filament/App/Pages/Reports/DataHealth.php)
- **Domain Calculator**: [`DataHealthReport`](../../app/Domain/Metrics/Reports/DataHealthReport.php)
- **Primary Sources**: `branches`, `ingest_batches`, `ingest_rejections`, `reconciliation_issues`, `wh_orders`, `agg_branch_daily`
- **Widgets Included**:
  1. `DataHealthOverviewWidget`: Summary badges: Online Branches, Delayed Branches, Offline Branches, Ingestion Rejections (last 7 days), Open Reconciliation Issues.
  2. `BranchStatusTableWidget`: Branch telemetry table: POS Version, Last Heartbeat, Last Batch Arrival, Pending Outbox Count, Failed Outbox Count, Status badge (`online`, `delayed`, `offline`).
  3. `CompletenessCalendarWidget`: Calendar matrix per branch showing status of each date: Complete (green), Closed/Unreconciled (amber), Open/Today (blue), Missing (red), Not Opened (gray).
  4. `ReconciliationIssuesTableWidget`: Discrepancy table comparing POS `day_summary` against platform aggregates. Includes action button: **"Request Resync" (طلب إعادة مزامنة)** to trigger remote recovery.
  5. `IngestRejectionsTableWidget`: Audit log of documents rejected at HTTP ingress with reason, document type, and UUID.
  6. `ProfitDriftTableWidget`: Identifies dates where POS local order profit (`wh_orders.profit_pos`) drifted from platform gross profit by $> 1.0\%$.
