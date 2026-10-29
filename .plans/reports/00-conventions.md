# Report Conventions (shared by every report)

Every report plan references this file. KPI formulas are implemented **once** in
`App\Domain\Metrics\Kpi` and every report calls them — a report must never re-implement a formula.

---

## 1. Time

| Rule | Detail |
|---|---|
| Business date | Every fact has a `business_date` sent by the POS (a day opened before midnight keeps its date after midnight). All reports group by `business_date`, never by calendar timestamp. |
| Timezone | Per branch (`branches.timezone`, default `Africa/Cairo`). Hour-of-day uses branch local time. |
| Period presets | Today · Yesterday · This week (Sat→Fri, Egyptian week) · Last week · This month · Last month · This quarter · This year · Custom range |
| Granularity | Day / Week / Month, chosen automatically (≤ 31 days → day, ≤ 26 weeks → week, else month) and switchable |

### Comparison modes (`compareTo`)

| Mode | Meaning | Default for |
|---|---|---|
| `previous` | Immediately preceding period of equal length | Month, custom |
| `same_weekday_last_week` | Same weekdays one week earlier (restaurant sales follow weekday seasonality) | Today, Yesterday, This week |
| `same_period_last_year` | Same dates last year, aligned by weekday (364 days back) | Year, quarter |
| `none` | No comparison | — |

**Partial current period:** when the period includes today, the comparison is cut to the same
elapsed portion (same number of days, and for today the same hour, using `agg_branch_hourly`).
A month-to-date is compared with the first N days of last month — never with the full month.

---

## 2. Branch selection

Every report has the same branch picker:
- **All accessible branches** (default)
- **Restaurant (brand)** — all branches of one restaurant
- **Saved set** (`branch_sets`) — e.g. "فروع القاهرة"
- **Pick branches** — multi-select

The picker only lists branches from `AccessibleBranches::for($user)`. A branch manager with one
branch sees no picker; group-level widgets are hidden for them (see `features/access-control.md`).

**Like-for-like toggle** (comparison reports): include only branches that have data in *both* the
current and the comparison period (excludes newly opened / closed branches from growth figures).

---

## 3. KPI glossary (single source of truth)

All amounts in EGP. "completed" = `status = completed AND deleted_at IS NULL`.
Ratios over a period are **ratio of sums**, never the average of daily ratios.

| Code | KPI (AR / EN) | Formula | Column(s) in `agg_branch_daily` |
|---|---|---|---|
| K01 | عدد الطلبات / Orders | count of completed orders | `orders_count` |
| K02 | إجمالي المبيعات قبل الخصم / Gross sales | Σ `sub_total` of completed orders | `gross_sales` |
| K03 | الخصومات / Discounts | Σ order `discount` + Σ item `item_discount` | `discounts` |
| K04 | صافي المبيعات / Net sales | Σ (`total` − `tax`) of completed orders (service charge is revenue, tax is not) | `net_sales` |
| K05 | المرتجعات / Returns | Σ `total_refund` of returns, by the **return's** business date | `returns_amount` |
| K06 | **المبيعات / Sales (headline)** | K04 − K05 | `net_sales_after_returns` |
| K07 | متوسط قيمة الطلب / Avg ticket | K04 ÷ K01 | derived |
| K08 | تكلفة المبيعات / COGS | Σ `quantity × unit_cost` of completed order items − Σ returned qty × unit_cost where `reverse_stock = true` | `cogs − returned_cogs_recovered` |
| K09 | مجمل الربح / Gross profit | K06 − K08 | `gross_profit` |
| K10 | هامش مجمل الربح / Gross margin | K09 ÷ K06 | derived |
| K11 | المصروفات / Expenses | Σ expenses (all categories) | `expenses_total` |
| K11a | تكلفة العمالة / Labor | expenses whose `expense_type.category = labor` | `labor_cost` |
| K12 | الهالك / Waste cost | Σ closed waste `total` | `waste_cost` |
| K13 | عجز الجرد / Inventory variance | Σ `(stock_quantity − real_quantity) × price` of closed stocktakings (positive = shortage) | `inventory_variance_cost` |
| K14 | **صافي الربح / Net profit** | K09 − K11 − K12 − K13 | `net_profit` |
| K15 | **هامش الربح / Net margin** | K14 ÷ K06 | derived |
| K16 | نسبة تكلفة الخامات / Food cost % | K08 ÷ K06 | derived |
| K17 | نسبة الهالك / Waste % | K12 ÷ K06 (secondary: K12 ÷ (K08 + K12) = waste share of material usage) | derived |
| K18 | نسبة العمالة / Labor % | K11a (allocated, §4) ÷ K06 | derived |
| K19 | Prime cost % | (K08 + K11a allocated) ÷ K06 | derived |
| K20 | صافي المشتريات / Net purchases | Σ purchase invoices − Σ purchase returns (cash-flow info, **not** in profit) | `purchases_total − purchase_returns_total` |
| K21 | نسبة الإلغاء / Cancel rate | cancelled ÷ (completed + cancelled) | `cancelled_count`, `orders_count` |
| K22 | نسبة المرتجع / Return rate | K05 ÷ K04 | derived |
| K23 | نسبة تحقيق المستهدف / Target achievement | actual ÷ target | see report 08 |
| K24 | النمو / Growth | (current − comparison) ÷ |comparison|; shown as "—" when comparison = 0 | derived |
| K25 | عجز النقدية / Cash deficit | Σ `end_cash − real_cash` where positive, over closed shifts | `cash_deficit_total` |

Division by zero → `null`, displayed as "—", never 0 % and never ∞.

---

## 4. Monthly-paid costs (labor, rent)

Salaries and rent are usually recorded once a month, which makes daily/weekly labor % meaningless.

- Aggregates store **actual paid** amounts on the day they were recorded.
- For periods shorter than a full month, K18/K19 use **allocated** cost:
  `allocated_daily = monthly_basis ÷ days_in_month`, where `monthly_basis` =
  1. the month's actual total if the month is closed, else
  2. Σ `avg_month_rate` of the branch's labor expense types if set, else
  3. trailing 3-month average actual labor.
- The UI shows both "مدفوع فعلياً" (paid) and "محمّل" (allocated) with a tooltip explaining the basis.

---

## 5. Data completeness

Each `(branch, business_date)` has a status, shown as a coloured dot wherever a branch/day appears:

| Status | Rule | Badge |
|---|---|---|
| `complete` | day closed on POS **and** reconciled with `day_summary` | ✓ green |
| `closed` | day closed, reconciliation pending or mismatched | amber |
| `open` | today / day not yet closed — figures are live | blue "مباشر" |
| `missing` | branch active, date ≥ `opened_on`, no data received | red |

- Report header shows **coverage**: "18 من 20 فرع مكتمل البيانات".
- Rankings (07), anomaly detection (12) and AI suggestions exclude branch-days that are `missing`
  or `closed`-mismatched; the UI lists the excluded branches.
- Dates before a branch's `opened_on` are not "missing" — they are excluded from like-for-like.

---

## 6. Presentation

- RTL Arabic UI; numbers in Western digits with thousands separators (`48,210.00 ج.م`).
- Money: 0 decimals in cards/charts, 2 in tables and exports. Percentages: 1 decimal.
- Growth arrows: green = good direction for that KPI (sales up = good, food cost % up = bad).
- Colours per branch are stable across all reports (hash of branch id → palette).
- Every report: export to Excel (raw table) and PDF (printable layout); URL carries filter state.
- Drill-down path, everywhere: group → branch → day → shift → order list.

---

## 7. Implementation pattern (per report) — Filament `app` panel

UI is Filament only. In each report file, "Route `/analytics/x`" means the Filament page slug
`/app/reports/x`; "cards" = `StatsOverviewWidget`, "charts" = `ChartWidget` (Chart.js),
"tables/matrices" = Filament table on the page or a `TableWidget`, "drawer" = Filament slide-over action.

```
app/Domain/Metrics/Reports/<Report>Report.php           // builds the data via MetricsQuery + Kpi (pure PHP, tested)
app/Filament/App/Pages/Reports/<Report>.php             // page: uses HasReportFilters, lists its widgets
app/Filament/App/Widgets/Reports/<Report>/*Widget.php   // sections of the page
resources/views/filament/app/pages/reports/<report>.blade.php   // only if the page needs a custom view
tests/Feature/Reports/<Report>ReportTest.php            // fixture → asserted numbers (service level)
tests/Feature/Reports/<Report>PageTest.php              // Livewire: page renders, access scoping
```

### Shared test fixture

`Tests\Fixtures\TwoBranchesTwoWeeks`: org with restaurant R, branches **A** (busy) and **B** (quiet),
14 closed days, known orders/items/expenses/waste/one stocktaking/one return/one cancelled order,
one day where B is `missing`, a labor expense type with `avg_month_rate = 30000`.
Every report test asserts exact numbers against this fixture.
