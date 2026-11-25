# Alerts Engine (`alert_rules` & `alerts`)

## What
Rule-based and anomaly-driven operational alerting across branches (`A1`–`A8` in the original alerts spec): sales below target pace, month-at-risk projection, waste spike, expense spike, food cost spike, unusual sales drop, cash deficit, and branch offline / day not closed.

## Why
Chain owners and branch managers need proactive notifications when sales slip behind daily target pace or waste/expenses spike, without manually checking reports every hour. Currently only the `alerts.manage` and `alerts.acknowledge` Spatie permissions exist in `RolesAndPermissionsSeeder`, with no tables, evaluator, or UI.

## Where
- **Migrations:** `alert_rules`, `alerts` (with `dedupe_key` unique/scoped per `code:branch:date`).
- **Domain:** `app/Domain/Alerts/` (`EvaluateAlertRules`), triggered by a listener on `App\Events\BranchDayUpdated` and scheduled checks in `routes/console.php`.
- **UI & Tools:** Alert feed and rules management in `app/Filament/App/` (plus optional assistant action tools such as `acknowledge_anomaly` / `create_alert_rule`).
- **Seed:** Default `alert_rules` seeded per organization; permissions already seeded in `database/seeders/RolesAndPermissionsSeeder.php`.

## Done when
- `BranchDayUpdated` triggers rule evaluation (`A1`, `A3`–`A5`, `A7`) and scheduled checks cover intraday `A1`, daily `A2`, and offline `A8`.
- Duplicate evaluations do not duplicate notifications (`dedupe_key`), and alerts auto-resolve when the condition clears.
- `branch_manager` users see and receive alerts only for their `AccessibleBranches` branches.
- Feature tests assert thresholds, deduplication, auto-resolution, and branch isolation against `Tests\Fixtures\TwoBranchesTwoWeeks`.
