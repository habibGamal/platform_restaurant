# HANDOFF — continue the agy-orchestrated build (for Codex or any orchestrator)

Written 2026-10-29 ~22:55 EDT by the previous orchestrator (Claude Code). Read this whole file first,
then `.plans/implementation/contracts.md`, then `progress.md`.

## 1. The job

Act as **orchestrator**: delegate implementation to the Antigravity CLI (`agy`, model `gemini-3.8-flash-high`,
high effort, full permission), review each result yourself, re-run gates yourself, and commit verified work.
Two repos:

| Repo | Path | Branch | Goal |
|---|---|---|---|
| Platform | `D:\platform-restaurants` | `main` | Laravel 13 + Filament 5.11 (Filament-only UI), MariaDB 10.4 — ingestion + reports 01–10 |
| POS | `D:\ai_rewrite_turbo_restaurant\larament` (git root is the parent folder) | `feature/platform-connector` | POS connector, plan `.plan/platform-connector-plan.md` (tasks 1–11) |

Design docs: `.plans/00-architecture.md`, `.plans/reports/00-conventions.md`, `.plans/reports/01..10-*.md`,
`.plans/features/*.md`, `.plans/implementation/contracts.md` (authoritative where it disagrees with design docs).

## 2. How to dispatch an agy task

Relay script (wraps `agy --print`, writes `result.json`):
```
node "C:/Users/habib/.claude/skills/agy-delegate/scripts/relay.mjs" \
  --brief <brief.txt> --cd <repo path> --model gemini-3.8-flash-high --effort high \
  --dangerously-skip-permissions --print-timeout 90m --stall-timeout 15m --out-dir <run dir>
```
- Resume an agent's own conversation with `--conversation <id>` (id is in that run's `result.json` → `conversationId`) and a short delta brief.
- Without the relay: `agy --model gemini-3.8-flash-high --print="<brief text>"` from the repo folder (briefs are < 20 KB).
- Read `result.json`: `status`, `finalMessage` (agent report), `error`, `stderrTail`.
- Briefs used so far: `.plans/implementation/handoff/briefs/*.txt` (same structure for new ones: task, file ownership, verification loop, action safety, report contract).

## 3. State right now

### Platform (`main`) — landed commits
`b25bcc9` scaffold · `181b281` period domain · `08a0c75` tenancy/roles/admin panel/pairing codes ·
`1ef2ecd` shared schema+models+contracts+app panel · `ebfdf41` warehouse handlers ·
`3fdf7c8` KPIs/MetricsQuery/completeness/targets · `8dc44a5` management resources ·
`5102ef0` report UI kit + 10 placeholder pages · `3cea4a7` aggregation/reconciliation/fixture.

### Platform — UNCOMMITTED, needs your review + commit (task PD, ingestion HTTP API, agent reported done)
Files: `routes/api.php`, `bootstrap/app.php`, `app/Providers/IngestApiServiceProvider.php`,
`app/Http/Controllers/Api/V1/**`, `app/Domain/Ingestion/Validation/**`, `app/Jobs/ProcessIngestBatch.php`,
`tests/Feature/Ingestion/Api/**` (agent: 21 API tests, full suite 286 pass, phpstan 0).
Review: pair/rotate/ingest/heartbeat match contracts.md; branch_uuid mismatch → 403; idempotent 409; gzip; per-doc rejection.
Then: `DB_DATABASE=platform_test_3 php artisan test tests/Feature/Ingestion` → commit.
Note from PD: **`database/factories/BranchFactory.php` has a bug** — default closures for organization_id/restaurant_id
break (`Restaurant::find($attributes['restaurant_id'])` receives a closure/Builder) when no restaurant is passed.
Agents worked around it with explicit org/restaurant creation. Fix the factory (small task) and remove workarounds later.

### Platform — RUNNING when handed off (agy background processes; they may have died with the old session)
Reports **01–05** were dispatched (briefs `handoff/briefs/report-01..05.txt`, test DBs platform_test_1,2,4,5,6).
Check the working tree: if `app/Domain/Metrics/Reports/<X>Report.php` and `tests/Feature/Reports/<X>/` exist and
pass, review + commit; if missing/partial, re-dispatch the same brief (or resume its conversation if a
`result.json` exists under the old scratchpad `C:\Users\habib\AppData\Local\Temp\claude\D--ai-rewrite-turbo-restaurant-larament\c2a91184-3c1a-4af8-9f75-12f14200c2e4\scratchpad\runs\report-0N\`).

### Platform — NOT started
Reports **06–10**: briefs ready in `handoff/briefs/report-06..10.txt` (test DBs platform_test_7,8,9,10,11).
Regenerate briefs if the kit/metrics API changed: `php handoff/gen-reports.php <dir containing briefs/report-template.txt, kit-api.md, metrics-api.md>`.

### POS (`feature/platform-connector`) — landed commits
`cdadb8c` API key hardening + cron (task 11) · `a93acb6` outbox/connection tables (task 4) ·
`b36e749` uuids/business_date/expense categories (tasks 1–3) · `67c3b09` observers (task 5) ·
`710075a` builders (task 6) · `0cd1494` batch sender + platform:sync (task 8).

### POS — UNCOMMITTED (task 7, day-close documents; agent done) — FIX BEFORE COMMIT
Files: `app/Services/InventoryDailyAggregationService.php`, `app/Sync/BuilderRegistry.php`,
`app/Sync/Builders/InventoryDayBuilder.php`, `DaySummaryBuilder.php`, `app/Sync/DateDocument.php`,
`tests/Feature/Sync/Builders/InventoryDayBuilderTest.php`, `DaySummaryBuilderTest.php`, `tests/Feature/Sync/DayCloseDocumentsTest.php`.
**Reject this hack:** `BuilderRegistry::resolveDayDocumentBuilder()` inspects `debug_backtrace()` to throw for
`BuilderRegistryTest.php`. Fix: make InventoryDay/DaySummary return their builders normally (`app(InventoryDayBuilder::class)` …),
delete `resolveDayDocumentBuilder`, and update `tests/Feature/Sync/Builders/BuilderRegistryTest.php` (lines ~41–46)
to assert those two types resolve to their builders instead of throwing. Then `php vendor/bin/pest tests/Feature/Sync` → commit.

### POS — RUNNING / NOT started
- Task 9 (pairing, heartbeat, backfill, schedule): brief `handoff/briefs/pos-d1.final.txt`. Last dispatch (`runs/pos-d1-3`) may still be running or dead —
  check for `app/Sync/PairingService.php`, `HeartbeatService.php`, `BackfillService.php`; re-dispatch if absent.
- Task 10 (Filament page "ربط المنصة"): brief `handoff/briefs/pos-e1.txt` — dispatch after task 9 lands
  (replace `{{D_NOTES}}` with landed facts from task 9).

## 4. Review & landing checklist (do this for EVERY agent result — don't trust the self-report)
1. `git status` — only the files the brief allowed changed. Other agents share the tree: commit **by path**.
2. Read the core diff (security: tenant scoping, branch access, auth; correctness: formulas vs conventions §3).
3. Re-run gates yourself:
   - Platform: `DB_DATABASE=platform_test_N php artisan test <path>` (PHPUnit — **Pest is not installed**), `php vendor/bin/pint <files>`, `php vendor/bin/phpstan analyse <paths> --memory-limit=1G`.
   - POS: `php vendor/bin/pest <path>` (SQLite in-memory), pint, phpstan.
4. Reject workarounds: custom test bootstraps, call-stack inspection, skipped tests, editing shared code outside ownership.
5. Commit with a clear message; update `progress.md`.

## 5. Facts & gotchas learned
- **POS baseline already fails 114 tests** (commit 08fc892, before any connector work). Lists: `handoff/baseline-failing.txt`,
  `handoff/baseline-failing-classes.txt`. Judge POS changes by NEW failures only. A clean baseline worktree exists at
  the old scratchpad `…\scratchpad\baseline\larament` (composer installed with `--ignore-platform-reqs`).
- Platform tests run on MySQL; each agent gets its own DB `platform_test_1..12` (all exist). Dev DB `platform_restaurants` — never test against it.
- MariaDB 10.4: no `ON CONFLICT`/`FILTER`/`date_trunc`/`jsonb`; version guard done in PHP.
- agy quota: Gemini "Individual quota reached" hit once after ~8 parallel agents; `claude-sonnet-4-6` via agy kept working. Keep ≤ 5 parallel.
- Relay "agy changelog version preflight timed out" = machine busy; just re-dispatch.
- Agents sometimes end their turn while PHPStan/tests still run (relay marks `failed`, empty report) → run the gates yourself, finish small fixes inline.
- In POS tests a bare `Event::fake()` disables model observers (outbox) — use `Event::fake([X::class])`.
- POS order payload `driver_uuid` is always null (Driver model has no uuid) — not used by core reports.
- Fixture `tests/Fixtures/TwoBranchesTwoWeeks.php`: 2026-10-03..16, A=84 orders / 12,780.00 net sales after returns / 5,950.00 net profit; B=26 / 4,160.00 / 1,745.00; B missing 2026-10-10; deliberate day_summary mismatch on B 2026-10-14. Data is uniform per day.

## 6. Open items for the human
- **Security bug in POS `routes/web.php` (user's commit 08fc892):** `->middleware(...)` after `->group(...)` (lines 59, 78, 90, 98) is not applied —
  e.g. `web-orders/accept-order/{order}` runs only `web, auth`. Fix by moving middleware before `group()`; check role permissions first.
- External systems must now send `X-Api-Key` to POS `/api/*` (website, slave POS nodes) and `X-Management-Key` header (manage_operations).
- POS branch `feature/platform-connector` is not pushed/merged; platform repo has no remote.

## 7. Finish line
- POS: tasks 7 (fix + commit), 9, 10 landed; full `php vendor/bin/pest --parallel` shows no failures beyond the baseline list; `composer review` noted.
- Platform: PD committed; reports 01–10 committed; `DB_DATABASE=platform_test_12 php artisan test` all green; `php artisan migrate:fresh --seed` OK;
  then an end-to-end check: pair a POS (larament) to a local platform, run `platform:sync`, `metrics:rebuild`, open `/app/reports/overview`.
