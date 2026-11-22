# Changelog

All notable changes to this project. Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
This project is not versioned; history is grouped by month.

## [Unreleased]

### Added
- **Menu Intelligence Precomputation Subsystem:** Nightly background pipeline (`BuildMenuIntelligence`, `menu:build-intelligence`) precomputing item profiles, rolling period sales statistics, APRIORI basket affinities, strategic segment rankings, and candidate combo meal packages into 8 new analytical database tables ([docs](docs/features/menu-engineering-and-roles.md)).
- **Menu Management Review Interfaces:** Three interactive Filament review pages under Management: Item Cards (`/app/management/item-cards`), Recipe Component Classes (`/app/management/component-classes`), and Combo Candidates (`/app/management/combo-candidates`) ([docs](docs/features/menu-engineering-and-roles.md)).
- **Precomputed Menu Intelligence AI Tools:** Added `rank_items`, `get_item_card`, `get_combo_candidates`, and `get_affinities` to `MarketingSpecialist`, enforcing segment-based item ranking and margin floors ([docs](docs/features/ai-assistant.md)).
- **Global Topbar Scope Selector:** Added persistent `ReportScopeSelector` Livewire component to the Filament App Panel topbar (`PanelsRenderHook::TOPBAR_END`) for selecting Restaurant / Brand and Branches across all analytics reports ([docs](docs/features/analytics-reports.md)).
- **Order Type Sales Breakdown:** Added `OrderTypeSales` domain service and `OrderTypeSalesWidget` in the Sales Comparison report, categorizing orders and sales by channel (`dine_in`, `takeaway`, `delivery`, `drive_thru`, etc.) ([docs](docs/features/analytics-reports.md)).
- **Bulk Restaurant Target Allocation:** Added `restaurantTargets` bulk header action in `ListTargets` allowing operators to allocate monthly targets for sales, profit, orders, and food cost percentage across branches using historical weekday weights or flat distribution ([docs](docs/features/targets-and-ranking.md)).
- **In-Memory Table Search & Chart Percentage Formatting:** Upgraded `ArrayTableRecords` to provide full-text and column-specific searching, multi-column sorting, and selectable page sizes (15, 30, 50, 100), plus raw JS percentage formatting in `BaseReportChartWidget` ([docs](docs/features/analytics-reports.md)).
- **In-App Documentation Server & Viewer:** Added `/docs/{file?}` endpoint in `routes/web.php`, App Panel navigation item "التوثيق الفني", Artisan command `docs:serve`, and `composer docs` / `npm run docs` scripts ([docs](docs/interfaces.md)).
- Complete Engineering Atlas developer handbook, architecture references, data models, interfaces catalog, operational runbooks, and 10 capability guides under `docs/` ([docs](docs/README.md)).
- Project root entry point and onboarding guide ([README.md](README.md)).

### Changed
- Refined AI Assistant coordinator planning protocol to output concise Egyptian Arabic plans (`خطة: ...`) and silenced intermediate chat narration during tool execution ([docs](docs/features/ai-assistant.md)).
- Report tool response envelopes now pass explicit definitions clarifying stock-count variance sign (`inventoryVarianceCost`: positive = shortage loss, negative = surplus gain) and net profit formulas ([docs](docs/features/ai-assistant.md)).
- Enhanced `ResolvePeriod` to parse multi-month conversational date ranges (`monthsRange`) ([docs](docs/features/ai-assistant.md)).

### Fixed
- Corrected `MetricsQuery::branchIds()` branch set and restaurant candidate scoping to intersect properly with user accessible branch IDs ([docs](docs/features/metrics-query-and-kpis.md)).
- Automated dispatch of debounced `BuildMenuIntelligence` job from `ProcessIngestBatch` whenever catalog items, recipes, or back-dated sales orders are ingested ([docs](docs/features/batch-ingestion.md)).

### Upgrade notes
- Run `php artisan migrate` to create Menu Intelligence tables (`component_classes`, `category_profiles`, `item_profiles`, `item_period_stats`, `item_affinities`, `item_rankings`, `combo_candidates`, `menu_intelligence_builds`).
- Ensure cron triggers `php artisan schedule:run` every minute to execute the nightly `BuildMenuIntelligence` job scheduled at 04:30.

## 2026-11 (untagged)

### Added
- **AI Assistant & Multi-Agent Coordinator:** Integrated a multi-agent restaurant analyst coordinator (`RestaurantAnalyst`) with specialized agents (`FinanceSpecialist`, `OperationsSpecialist`, `MarketingSpecialist`, `DataChecker`) and interactive web chat ([docs](docs/features/ai-assistant.md)).
- **Live Assistant Work Log & Streaming:** Added background execution for assistant queries via `RunAssistantQuestion` with real-time SSE progress reporting and persistent run events ([docs](docs/features/ai-assistant.md)).
- **Whitelisted Metrics Query Engine:** Added the secure `query_metrics` tool allowing the AI assistant to safely execute pre-validated KPI queries against aggregate and dimension models ([docs](docs/features/ai-assistant.md)).
- **Menu Engineering & Item Role Classification:** Introduced automated BCG matrix classification (Stars, Plowhorses, Puzzles, Dogs) via hard business signals and LLM evaluation ([docs](docs/features/menu-engineering-and-roles.md)).
- **Menu Analytics Tools:** Added basket analysis ("bought together"), combo meal builder recommendations, item timing, and sales trend exploration tools ([docs](docs/features/menu-engineering-and-roles.md)).
- **Item Roles Review Interface:** Created an interactive Filament management page (`ItemRolesPage`) for operators to review, filter, and manually override item classifications ([docs](docs/features/menu-engineering-and-roles.md)).
- **AI Token Budgets & Billing:** Added per-organization AI provider configuration (OpenAI, Azure, custom proxies) and monthly token quota metering with automatic cooling guards ([docs](docs/features/ai-assistant.md)).

### Changed
- Shared organization-level AI settings and token quotas across all subagents within a single question run ([docs](docs/features/ai-assistant.md)).
- Enabled response streaming by default for OpenAI and compatible proxy providers ([docs](docs/features/ai-assistant.md)).

### Fixed
- Dedicated queue routing for assistant query jobs to isolate AI latency from real-time POS ingestion ([docs](docs/features/ai-assistant.md)).
- Bounded on-demand menu role classification with rate limits and execution timeouts to prevent runaway LLM spending ([docs](docs/features/menu-engineering-and-roles.md)).
- Hardened reasoning option parameter mapping for OpenAI-compatible chat completion gateways ([docs](docs/features/ai-assistant.md)).

### Upgrade notes
- Run `php artisan migrate` to create `ai_settings`, `ai_run_events`, and `dim_item_roles` tables.
- Configure queue workers to listen to the `assistant` queue (`php artisan queue:work --queue=assistant,default`).
- Set provider credentials (`OPENAI_API_KEY`) or configure organization-specific credentials via the App Panel under **Management > AI Settings**.

## 2026-10 (untagged)

### Added
- **Multi-Tenant Foundation & Admin Panel:** Implemented tenancy hierarchy (Organizations, Restaurants, Branches, Users, Branch Sets) with Spatie RBAC and two Filament panels (`/admin` and `/app`) ([docs](docs/features/tenancy-and-access-control.md)).
- **Cryptographic POS Pairing & Ingestion API:** Added 8-digit one-time code pairing handshake, Sanctum bearer tokens with `ability:ingest`, automatic token rotation, and gzip-compressed batch submission at `/api/v1/ingest/batches` ([docs](docs/features/pos-pairing-and-heartbeat.md), [docs](docs/features/batch-ingestion.md)).
- **Bidirectional POS Heartbeat Protocol:** Introduced telemetry reporting and queued remote management commands via `/api/v1/heartbeat` ([docs](docs/features/pos-pairing-and-heartbeat.md)).
- **Idempotent Ingestion & Version Guards:** Handled 16 POS document types with document hash idempotence, entity version checks, and soft tombstones in raw warehouse storage ([docs](docs/features/batch-ingestion.md)).
- **Concurrency Fencing & Dirty Date Outbox:** Protected ingestion batches with Redis worker leases, monotonic fencing tokens (`FencedWorkerException`), and durable dirty date tracking (`ingest_dirty_dates`) ([docs](docs/features/crash-recovery-and-fencing.md)).
- **Daily Aggregate Engine & Reconciliation:** Built `BranchDayRebuilder` to aggregate orders, shifts, and inventory into daily KPI rollups, with automated summary reconciliation and POS resync commands ([docs](docs/features/warehouse-and-aggregates.md)).
- **Metrics Query Engine & KPI Catalog:** Implemented `MetricsQuery` with access-safe branch scoping, 364-day weekday alignment (52-week LFL), period presets, and standard formulas for KPIs $K01$ through $K25$ ([docs](docs/features/metrics-query-and-kpis.md)).
- **Production Analytics Reporting Suite:** Launched 10 interactive analytics dashboards (Group Overview, Sales Comparison, Profitability, Product Performance, Food Cost & Waste, Expenses & Purchases, Shifts & Weekdays, Branch Ranking, Targets vs Actual, Data Health) with in-memory table pagination ([docs](docs/features/analytics-reports.md)).
- **Target Planning & Historical Distribution:** Added target management with 8-week history weekday weight distribution, calendar event exclusions, and restaurant-to-branch bulk allocation ([docs](docs/features/targets-and-ranking.md)).
- **Multi-Criteria Branch Ranking:** Implemented dynamic 7-KPI branch scoring and rank calculation using configurable scoring profiles ([docs](docs/features/targets-and-ranking.md)).
- **Cascading Data Deletion:** Implemented safe cascading deletion for branches and organizations across 28 warehouse and aggregate tables plus storage cleanup ([docs](docs/features/tenancy-and-access-control.md)).

### Fixed
- Resolved lock contention during concurrent ingestion batch processing by splitting transactional warehouse writes from asynchronous aggregate scheduling ([docs](docs/features/crash-recovery-and-fencing.md)).
- Prevented corrupt POS shift timestamps and negative durations from failing daily aggregate rebuild jobs ([docs](docs/features/warehouse-and-aggregates.md)).

### Upgrade notes
- Initial platform release: run `composer install && npm install && npm run build`.
- Run `php artisan migrate --seed` to initialize platform schema, Spatie roles, and default permissions.
- Ensure supervisor runs `php artisan queue:work` and cron triggers `php artisan schedule:run` every minute.
