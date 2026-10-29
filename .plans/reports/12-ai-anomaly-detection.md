# 12 — AI: Anomaly Detection (اكتشاف انخفاض المبيعات أو ارتفاع التكلفة غير المعتاد)

**Requirement:** §3 — detect unusual sales drops or cost increases.
**Tier:** AI · **Route:** `/analytics/ai/anomalies` · feeds alerts (report 18)

## Questions it answers
1. Which branch had an unusual day (sales, orders, ticket, food cost, waste, expenses, cancellations, cash deficit)?
2. How unusual is it, and what is the likely cause (which channel / category / item / expense type moved)?

## Method (`AnomalyDetector::scan(Branch, date): Collection<Anomaly>`)
Robust, explainable statistics — no black-box model.

1. **Baseline** for metric *m* on date *d*: the same weekday in the previous 8 weeks, excluding days in `calendar_events` and days not `complete`. Need ≥ 5 samples, else skip (status "insufficient history").
2. **Robust z-score**: `z = (x − median) ÷ (1.4826 × MAD)`; if MAD = 0 use `max(MAD, 5 % of median)`.
3. **Flag** when `|z| ≥ 3` **and** the absolute deviation ≥ the metric's materiality floor (e.g. sales: max(1,000 EGP, 10 % of median); waste: 300 EGP) **and** direction is bad for that metric.
4. **Severity**: `|z| 3–4` = medium, `> 4` = high; two consecutive anomalous days on the same metric → escalate one level.
5. **Explanation (drill-down attribution)**: for the flagged metric, compute the same deviation per dimension and rank contributors:
   - sales/orders → by channel (`sales_by_type`), by hour (`agg_branch_hourly`), by category/item (`agg_product_daily`)
   - food cost % → items whose unit_cost rose (vs 4-week avg) × qty, and mix shift to low-margin items
   - waste → items with largest waste cost delta
   - expenses → expense types with largest delta
   Top 3 contributors stored in the anomaly payload.

Metrics scanned daily per branch: K06, K01, K07, K16, K12, K11 (excluding monthly-paid categories), K21, K25.
Also intraday (every 30 min while day open): orders and sales so far vs baseline at the same hour → "today is running 35 % below a normal Thursday at 14:00".

## Storage
`ai_anomalies`: id, organization_id, branch_id, business_date, metric, value, baseline_median, z, severity, direction, contributors json, status (new/acknowledged/explained/dismissed), acknowledged_by, note.
Users can mark "explained" with a note (e.g. "power cut") → excluded from future baselines.

## Layout
Anomaly feed (newest first, filter by branch/metric/severity) · each card: metric, value vs normal range band, z, top contributors, sparkline of 8 baseline days, actions (acknowledge / explain / dismiss) · calendar heatmap of anomalies per branch.

LLM: optional one-line Arabic explanation per anomaly from the payload (same numeric post-check as report 11).

## Acceptance criteria
- Synthetic series with a −40 % Thursday → flagged high, contributors include the channel that dropped.
- Day inside a Ramadan `calendar_event` is excluded from baselines.
- Days with < 5 baseline samples produce no anomaly and status "insufficient history".
- "Explained" anomaly days are excluded from later baselines.
