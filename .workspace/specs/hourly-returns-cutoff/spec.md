# Hourly Sales-After-Returns Cutoff

## What
Add a trustworthy return event timestamp to the POS `order_return` ingestion contract and `wh_order_returns` / `agg_branch_hourly` so intraday "today vs comparison" reports can cut off `net_sales_after_returns` at the current hour.

## Why
As recorded in the ingestion recovery notes, `OverviewReport` cannot currently compare `net_sales_after_returns` through an hourly cutoff because `order_return` carries only `business_date` without a reliable local return timestamp. Today's report currently marks that comparison unavailable while keeping hourly order count and average ticket comparisons active.

## Where
- **POS & Ingest Contract:** `app/Domain/Ingestion/Validation/DocumentRules.php` (`order_return` schema), `app/Domain/Ingestion/Handlers/OrderReturnHandler.php`, and paired `larament` `OrderReturnBuilder`.
- **Schema & Aggregation:** `wh_order_returns` timestamp/hour column, `agg_branch_hourly`, `BranchDayRebuilder`, `MetricsQuery::hourlyTotals()`, and `OverviewReport`.

## Done when
- `order_return` documents carry a validated return timestamp (`created_at` / `returned_at` in branch local time) and `BranchDayRebuilder` aggregates hourly returns into `agg_branch_hourly`.
- `OverviewReport` displays intraday `net_sales_after_returns` comparisons cut off at the current hour matching `MetricsQuery::hourlyTotals()`.
- Backward compatibility is preserved for POS versions sending `schema_version: 1` without the new field.
