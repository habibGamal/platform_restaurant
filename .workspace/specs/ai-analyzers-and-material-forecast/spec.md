# Deterministic AI Analyzers & Material Needs Forecasting

## What
Standalone deterministic PHP analyzers (`GrowthAnalyzer`, `AnomalyDetector`, `ContributionAnalyzer`, `CostRatioAnalyzer`, `MaterialNeedsForecaster`) and precomputed insight tables (`ai_insights`, `ai_anomalies`, `ai_forecasts`) plus higher-level Assistant v2 tools (`OfferSimulator`, `ai_playbooks`, `ai_business_facts`).

## Why
Currently `SalesForecast` (`weekday_median_trend`) covers branch sales forecasting and `BuildMenuIntelligence` precomputes menu item profiles, affinities, rankings, and combos. However, the dedicated deterministic growth decomposition, robust z-score anomaly detector (`ai_anomalies`), profit contribution efficiency index, cost-ratio decomposition, and recursive recipe-based raw material stock-out forecasting (`wh_inventory_days` + `dim_recipes`) from the AI plans are not yet implemented.

## Where
- **Migrations:** `ai_anomalies`, `ai_forecasts`, `ai_insights` (and optionally `ai_playbooks`, `ai_business_facts` per v2 decision engine notes).
- **Domain:** `app/Domain/Analysis/` and `app/Domain/Forecast/MaterialNeedsForecaster.php`.
- **AI Tools:** `app/Ai/Tools/` wrappers feeding `FinanceSpecialist`, `OperationsSpecialist`, and `RestaurantAnalyst`.

## Done when
- Each analyzer is pure deterministic PHP tested against `TwoBranchesTwoWeeks` and synthetic series (e.g., anomaly `|z| >= 3` excluding `CalendarEvent` dates, recursive 2-level recipe material consumption, profit contribution shares summing to 100%).
- `insufficient_data` / `insufficient_history` guards prevent extrapolated answers when complete history is below minimum thresholds.
- Material needs forecasting uses `wh_inventory_days.end_qty` and `dim_recipes` to project stock-out dates.
