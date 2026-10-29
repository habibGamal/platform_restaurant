# 18 — Alerts (تنبيهات انخفاض المبيعات عن المستهدف وزيادة الهالك أو المصروفات)

**Requirement:** §3 — alerts when sales fall below target; alerts when waste or expenses rise.
**Tier:** rule-based alerts in core plan; anomaly-based alerts and AI explanation in AI plan.
**Route:** `/analytics/alerts` (feed) · rules in the `app` panel (Management group)

## Alert types

| Code | Name | Trigger | Default threshold | Tier |
|---|---|---|---|---|
| A1 | Sales below target pace | intraday (every 30 min) and at day close: actual to date vs target to date (report 08 distribution) | < 85 % at 16:00 or at close | core |
| A2 | Month at risk | daily: projected month-end achievement (report 08) | < 95 % | core |
| A3 | Waste spike | at waste doc close / day close: K17 today vs threshold or anomaly | waste % > org ceiling (default 3 %) | core / AI(anomaly) |
| A4 | Expense spike | expense recorded / day close: expense type month-to-date vs prorated `avg_month_rate`; or anomaly | > 120 % of budget pace | core / AI |
| A5 | Food cost spike | day close: K16 vs band or anomaly | > band ceiling | core / AI |
| A6 | Unusual sales drop | report 12 anomaly on K06/K01 (incl. intraday) | z ≤ −3 | AI |
| A7 | Cash deficit | shift closed with deficit (K25) | > 200 EGP | core |
| A8 | Branch offline / day not closed | report 10 rules | 60 min / 12:00 next day | core |

## Rules model
`alert_rules`: id, organization_id, code, enabled, scope (all | branch ids), params json (thresholds, times), channels (in_app, whatsapp, email), recipients (roles or recipient ids), cooldown_minutes, quiet_hours.
Defaults seeded per org from the table above.

## Evaluation
- Event-driven: `BranchDayUpdated` → `EvaluateAlertRules(branch, date)` for A1, A3–A5, A7.
- Scheduled: every 30 min for intraday A1/A6; daily 09:00 for A2; every 5 min for A8.
- `alerts`: id, rule_id, branch_id, business_date, code, severity, value, threshold, payload json, status (open/acknowledged/resolved), dedupe_key (`code:branch:date`), created_at.
- Dedup: one open alert per dedupe_key; re-notify only if severity escalates. Cooldown per rule. Auto-resolve when condition clears (e.g. sales recover above pace).

## Delivery
- In-app: Filament database notifications + `/analytics/alerts` feed + Reverb toast.
- WhatsApp: template `alert_ar` — body params: branch, alert name, value, threshold, link. Only `high` severity by default; batching: max 1 WhatsApp per recipient per 30 min (others grouped).
- AI plan adds a one-line explanation from report 12 contributors.

## Acceptance criteria
- A1 fires at 16:00 when fixture branch A is at 80 % of target-to-date and auto-resolves when it passes 85 %.
- Same condition evaluated twice → one alert, one notification.
- Quiet hours suppress WhatsApp but keep in-app alert.
- Branch manager receives alerts only for their branches.
