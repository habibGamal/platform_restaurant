# 15 — AI: Sales & Needs Forecasting (توقع المبيعات والاحتياجات المستقبلية)

**Requirement:** §3 — forecast sales and future needs based on historical data.
**Tier:** AI · **Route:** `/analytics/ai/forecast`

## Questions it answers
1. What sales and orders should each branch expect for the next 14 days / rest of the month?
2. Will the branch hit its monthly target (feeds report 08 with a statistical projection)?
3. How many units of each top item will be sold, and how much of each raw material is needed?
4. Which raw materials will run out before the next purchase?

## Data prerequisites
- ≥ 8 weeks of complete daily history per branch (else page shows "بيانات غير كافية").
- Needs forecasting requires `recipe` documents (`dim_recipes`) and `inventory_day` (`wh_inventory_days`) from the POS.

## Method (v1 in PHP; v2 optional Python service)
1. **Daily sales / orders per branch**: Holt-Winters additive with weekly seasonality (period 7) on the last 26 weeks; fallback seasonal-naive (avg of same weekday last 4 weeks) when history < 16 weeks.
   Calendar adjustment: multiplicative factors for `calendar_events` learned from last year's same event (if present).
2. **Backtest** nightly on the last 28 days (rolling origin) → store MAPE per branch; show accuracy badge. If Holt-Winters MAPE > seasonal-naive MAPE, use seasonal-naive for that branch.
3. **Prediction interval**: ± 1.28 × std of backtest residuals (80 % band).
4. **Item quantities**: per top-50 item, forecast = branch sales forecast × item's share of sales on that weekday (last 8 weeks).
5. **Raw material needs**: Σ item qty forecast × recipe quantity (recursive for manufactured components) = consumption forecast per raw material per day.
6. **Stock-out projection**: latest `end_qty` from `wh_inventory_days` − cumulative forecast consumption → projected stock-out date; flag if within 3 days. Suggested order qty = consumption for (lead time + 7 days) − current stock (lead time per supplier, default 2 days, configurable).

## Storage
`ai_forecasts`: branch_id, metric (sales|orders|item:<uuid>|material:<uuid>), date, value, lo, hi, model, generated_at.

## Layout
Forecast chart (history 8 weeks + 14 days ahead with band) per branch · month-end projection vs target · item forecast table · raw material needs table (current stock, daily need, days of cover, projected stock-out date, suggested order) · accuracy badge per branch.

## Schedule
Nightly 03:00 after day close; intraday refresh of today's remainder at 15:00.

## Acceptance criteria
- On a synthetic weekly-seasonal series, MAPE < 10 %; fallback selection logic picks the better model.
- Raw material needs for an item with a 2-level recipe equals hand-computed quantity.
- Branch with < 8 weeks history returns "insufficient data", no forecast rows.
