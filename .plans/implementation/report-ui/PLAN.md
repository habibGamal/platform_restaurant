# Report UI repair plan — 2026-10-31

User scope: audit and repair widgets across all ten reports; replace plain/unstructured
output with appropriate Filament components; stage report filters until an explicit
Apply click; give wide widgets a full row instead of squeezing them into two columns.
Implementation is delegated to agy; Codex reviews, verifies and commits each task.

## Findings

- The app panel has no custom Filament theme. Custom Tailwind classes in its Blade
  views therefore are not compiled into the stylesheet loaded by the panel.
- Most custom widget views start with x-filament::section and omit the outer
  x-filament-widgets::widget. That loses widget sizing/layout behavior.
- ReportPage uses HasFiltersForm, whose installed Filament 5 implementation binds
  directly to live `filters`; widgets consume those values immediately.
- BaseReportViewWidget renders raw HTML table cells rather than Filament tables.
- Some real matrices (P&L, category/branch, products, rankings, calendar and heatmap)
  need appropriate custom presentation, explicit sizing and controlled overflow.
- Overview freshness uses a half-width card and unstructured status text.

## Task 1 — Shared filters and theme foundation

Owner: agy, brief ui-01-foundation. Change ReportPage and subclass filter initialization
as necessary, the app panel provider, Vite entry and a dedicated Filament app theme.
Maintain separate draft and applied filter state. Add visible Filament Apply/Reset
actions. Only Apply updates widget filters, URL/session applied state and metric data;
draft dependent controls may update without querying reports. Preserve query-string
deep links, saved filters, page-specific defaults/fields and tenant permissions.
Validate custom dates before applying. Add behavior tests across all report pages.

## Task 2 — Standard tabular widgets

Owner: agy, brief ui-02-tables (after task 1). Use native Filament tables for ordinary
rows, including the shared BaseReportViewWidget consumers: sales KPI matrix, growth
decomposition, discount intensity, food-cost branch matrix, category performance,
targets branch/history widgets. Preserve values, deltas, row order, permissions,
drilldowns and empty states. Escape/sanitize all user-sourced labels. Use stable
array record keys and full-width table widgets. Do not turn real heatmaps or charts
into generic tables or remove useful report controls.

## Task 3 — Custom widgets, spacing and report layouts

Owner: agy, brief ui-03-layout (after task 2). Repair Overview freshness with a native
status table/badges and a Data Health link. Audit every custom Blade view and all
ten page/widget grids: P&L, product and expense matrices, rankings/leaders/podium,
completeness calendar, heatmap, notices and detail views. Use Filament tables,
sections, actions, badges and callouts where appropriate. Wrap every widget view
correctly. Wide matrices/tables/heatmaps use one full-width row; prioritize a
single-column report grid to avoid accidental cramped two-column rendering.
Keep KPI stats internally responsive and compact charts readable. Avoid giant empty
cards, dense wrapped number/delta strings and unbounded multi-date notes. Preserve
P&L labor basis, inventory variance exclusion and comparison controls.

## Review and acceptance

- Gemini 3.8 Flash High, high effort, full permission as already authorized.
- One relay job at a time, three bounded implementation briefs; no overlapping
  file edits or concurrent MySQL test/database/cache races. Never edit POS/backend
  accounting, warehouse, ingestion, migrations or existing developer data.
- All platform tests use DB_DATABASE=platform_test_12; PHPUnit, not Pest.
- Gates: targeted PHPUnit behavior/access tests, targeted Pint/PHPStan, npm run build,
  then the full platform PHPUnit suite after integration. Codex reruns gates.
- Verify real browser render in Arabic RTL, dark and light, desktop and narrow
  viewport. Check user screenshots' three examples first, then all ten reports.
  Test draft edit, Apply, Reset, empty/single/multiple branches and long labels.
- Capture representative screenshots, record any verification boundary, and commit
  only reviewed changes. No push/deploy or live-data mutations are part of this task.

## Documentation

Context7 tools are unavailable in this session. Use the installed Filament 5 source
and official references:
- https://filamentphp.com/docs/5.x/widgets/overview
- https://filamentphp.com/docs/5.x/tables/custom-data
- https://filamentphp.com/docs/5.x/styling/overview

Status: implemented through three agy tasks using Gemini 3.8 Flash High/high/full
permission, followed by Codex review and corrections. See REVIEW.md for validation.
