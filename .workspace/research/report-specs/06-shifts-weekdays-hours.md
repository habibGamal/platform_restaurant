# 06 — Shifts, Weekdays & Hours (مقارنة أداء الورديات وأيام الأسبوع)

**Requirement:** §2 — compare performance of shifts and days of the week.
**Tier:** core · **Route:** `/analytics/shifts` · **Conventions:** `00-conventions.md`

## Users & access
`org_owner`, `org_analyst`; `branch_manager` own branches.

## Questions it answers
1. Which weekday is strongest / weakest for each branch, and is the pattern the same across branches?
2. What are the peak hours (staffing and prep planning)?
3. How do shifts compare: sales per hour, avg ticket, cancellations, cash deficits — and by cashier?
4. Are there recurring cash deficits in a branch or with a cashier?

## Filters
Period (default **Last 8 weeks** — weekday averages need several weeks) · branch picker · channel · exclude calendar events (Ramadan/Eid) toggle (default on).

## Layout

1. **Weekday profile** — grouped bars: avg K06 per weekday (Sat→Fri) per branch; toggle "indexed to branch average = 100" to compare shapes across branch sizes.
   Avg = Σ sales on that weekday ÷ number of such weekdays with data (not ÷ calendar weekdays).
2. **Hour × weekday heatmap** — per branch (selector), cells = avg orders or avg sales in that hour slot; from `agg_branch_hourly`.
3. **Peak hours comparison** — line per branch: avg orders by hour of day.
4. **Shift table** — each closed shift: branch, date, weekday, start–end, duration, cashier, orders, K06, sales/hour, avg ticket, cancelled count, cash deficit (K25). Sort/filter; highlights deficits.
5. **Shift-slot comparison** — shifts bucketed by start time (e.g. morning < 15:00 ≤ evening) per branch: avg sales, sales/hour, avg ticket.
6. **Cashier summary** — per cashier (`dim_staff`): shifts, sales/hour, avg ticket, cancel rate, total and count of deficits.

## Data
- `agg_branch_daily` (weekday, net_sales_after_returns, orders_count)
- `agg_branch_hourly` (hour, weekday, orders_count, net_sales)
- `agg_shift` (all shift-level columns, cashier_uuid) + `dim_staff`
- `calendar_events` for exclusion.

## Edge cases
- A shift crossing midnight belongs to its `business_date`; hours after midnight show as 24, 25… in the heatmap (business-day hours), configurable to 0–23.
- Weekday averages with < 3 samples → greyed with "عينة صغيرة".
- Web orders moved to the next shift (POS setting) → counted in the shift that completed them (as sent in the order snapshot).
- Cashier deleted on POS (soft deleted user) → still shown by name from `dim_staff`.

## Acceptance criteria
- Fixture: weekday averages for A match expected values; the day where B is missing is excluded from B's averages (not counted as 0).
- Shift table total sales = report 01 total for the same period/branches.
- Deficit totals per cashier match fixture shifts.
