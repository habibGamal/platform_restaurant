# 10 — Branch Sync Health (حالة اتصال الفروع وجودة البيانات)

**Requirement:** platform need — every other report is only trustworthy if data is complete and fresh.
**Tier:** core · **Route:** `/analytics/data-health` (tenant) + operator panel page (all orgs)

## Users & access
`org_owner`, `org_analyst`: own org. `operator`: all orgs (Filament operator panel). Branch managers: own branch status strip only.

## Questions it answers
1. Is every branch connected and sending data now?
2. Which days are incomplete or don't reconcile with the POS?
3. Are documents being rejected (POS bug / version mismatch)?

## Layout
1. **Branch status table** — branch, status (online / delayed / offline), last heartbeat, last batch, POS version (+ "outdated" badge), schema version, pending docs on POS, oldest pending age, failed docs on POS, rejected docs on platform (7 days).
   - online: heartbeat < 10 min · delayed: 10–60 min or pending > 500 · offline: > 60 min.
2. **Completeness calendar** — branches × last 30 days, each cell = day status from conventions §5 (complete / closed / open / missing). Click → reconciliation detail.
3. **Reconciliation issues** — date, branch, field, POS value, platform value, diff, attempts, status; action "طلب إعادة مزامنة" (queues a `resync` heartbeat command).
4. **Rejections log** — doc type, uuid, reason, POS version, first/last seen.
5. **POS vs platform profit check** — days where Σ `profit_pos` differs from platform K09 by > 1 % (formula drift between POS versions).

## Data
`branches` (heartbeat fields), `ingest_batches`, `ingest_rejections`, `reconciliation_issues`,
`wh_day_summaries`, `agg_branch_daily.is_day_closed/is_reconciled`.

## Alerts (feed report 18)
- Branch offline > 60 min during its usual opening hours (learned from `agg_branch_hourly`).
- Day not closed on POS by 12:00 next day.
- Reconciliation still failing after 3 resyncs.

## Acceptance criteria
- Branch with heartbeat 30 min ago → "delayed"; 2 h → "offline".
- Fixture day where B is missing appears red in the calendar.
- "Resync" action creates a pending command returned in B's next heartbeat response.
