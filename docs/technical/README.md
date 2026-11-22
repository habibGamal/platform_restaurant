# Technical Documentation Set: `platform-restaurants`

Welcome to the technical documentation set for **`platform-restaurants`**, the central reporting, multi-unit analytics, and ingestion hub for restaurant chains, paired with the decentralized point-of-sale system **`larament`**.

This documentation describes the source inspected on 2026-11-01, including uncommitted changes. Other work was changing concurrently, so verify behavior and configuration against the checkout being deployed; source code and tests remain authoritative.

---

## 1. Documentation Index

The technical documentation is organized into 10 detailed topical manuals:

An additional [assistant work-in-progress inventory](11-assistant-work-in-progress.md) describes the concurrently changing AI-related source and its verification limits.

1. **[01. Architecture, Repository Structure, and Technology Stack](./01-architecture-and-stack.md)**
   *System architecture, multi-tier data flow, repository layout, frameworks, dependencies, and frontend runtime.*
2. **[02. Configuration, Environment, and Deployment Guide](./02-configuration-and-deployment.md)**
   *Environment variables (with defaults and security notes, zero secrets), system prerequisites, startup sequence, queue configuration, and scheduler.*
3. **[03. POS Pairing, Authentication, Heartbeat, and Transfer Pipeline](./03-pos-integration-and-transfer.md)**
   *Cryptographic pairing handshake, token rotation, API authentication (`auth:sanctum`, `ability:ingest`), rate limiters, bidirectional heartbeat command protocol, and POS outbox transfer engine (`larament`).*
4. **[04. Ingestion API, Envelope Schema, and Document Validation Rules](./04-ingestion-api-and-validation.md)**
   *Exact HTTP ingress contract (`POST /api/v1/ingest/batches`), 5 MB / 500-doc payload limits, bounded gzip decompression, HTTP response codes, and validation rules for all 16 POS document types.*
5. **[05. Concurrency, Distributed Leases, Fencing, Outbox, and Recovery](./05-concurrency-storage-and-recovery.md)**
   *Batch idempotency, version guards, worker leases, monotonic fencing tokens (`FencedWorkerException`), durable dirty date outbox (`ingest_dirty_dates`), and automated crash recovery (`ingest:recover`).*
6. **[06. Warehouse, Dimensions, Aggregates, Lineage, and Reconciliation](./06-warehouse-and-aggregation-engine.md)**
   *Mirrored raw warehouse schema (`wh_*`), reference dimensions (`dim_*`), precomputed aggregate models (`agg_*`), data lineage, daily rebuilder engine, and bounded POS summary reconciliation.*
7. **[07. Metrics Query, KPI Catalog, Overhead Allocation, and Period Math](./07-metrics-query-and-kpi-formulas.md)**
   *`MetricsQuery` engine, branch authorization, like-for-like (LFL) scoping, 364-day weekday alignment, time granularities, complete KPI catalog ($K01$–$K25$), overhead labor/rent allocation, and completeness evaluation.*
8. **[08. Comprehensive Reports Catalog & Table Pagination Engine](./08-reports-catalog.md)**
   *Detailed documentation of all 10 production reports (Overview, Sales Comparison, Profitability, Product Performance, Food Cost & Waste, Expenses & Purchases, Shifts & Weekdays, Branch Ranking, Targets vs Actual, Data Health), Order Type Sales, and the `ArrayTableRecords` in-memory pagination engine.*
9. **[09. Targets, History-Based Allocation, and Branch Ranking Engine](./09-targets-and-branch-ranking.md)**
   *Monthly target management, 8-week history weekday weight distribution with calendar event exclusions, bulk restaurant allocation, target achievement tracking, and 7-KPI multi-criteria branch ranking scoring.*
10. **[10. Tenancy, Security, Operations, and Plan vs Implementation Matrix](./10-tenancy-security-and-operations.md)**
    *Multi-tenant data isolation, Spatie roles & permissions matrix, branch access control, 28-table cascading deletion and raw storage purge, troubleshooting runbooks, and verified implementation versus unimplemented plans.*

---

## 2. Topic Coverage & Source Code Traceability Matrix

This matrix maps every functional domain to its technical manual, its implementation sources in `platform-restaurants`, and paired outbound transfer sources in `larament`:

| Functional Area | Documentation File | Central Platform Implementation (`platform-restaurants`) | Paired POS Source (`larament`) |
|---|---|---|---|
| **System Architecture & Stack** | [01-architecture-and-stack.md](./01-architecture-and-stack.md) | [`composer.json`](../../composer.json), `app/Providers/Filament/*`, `resources/css/filament/app/theme.css` | `larament:composer.json`, `larament:app/Sync/*` |
| **Config & Deployment** | [02-configuration-and-deployment.md](./02-configuration-and-deployment.md) | [`.env.example`](../../.env.example), `config/*`, [`routes/console.php`](../../routes/console.php) | `larament:config/services.php`, `larament:routes/console.php` |
| **POS Pairing & Token Handshake** | [03-pos-integration-and-transfer.md](./03-pos-integration-and-transfer.md) | [`PairController.php`](../../app/Http/Controllers/Api/V1/PairController.php), [`PairingCodeService.php`](../../app/Domain/Tenancy/PairingCodeService.php), [`Branch.php`](../../app/Models/Branch.php) | `larament:app/Sync/PairingService.php`, `larament:app/Sync/PlatformClient.php` |
| **API Auth & Throttles** | [03-pos-integration-and-transfer.md](./03-pos-integration-and-transfer.md) | [`IngestApiServiceProvider.php`](../../app/Providers/IngestApiServiceProvider.php), [`EnsureBranchActive.php`](../../app/Http/Controllers/Api/V1/Middleware/EnsureBranchActive.php) | `larament:app/Sync/PlatformClient.php` |
| **Heartbeat & Remote Commands** | [03-pos-integration-and-transfer.md](./03-pos-integration-and-transfer.md) | [`HeartbeatController.php`](../../app/Http/Controllers/Api/V1/HeartbeatController.php), [`BranchCommand.php`](../../app/Models/BranchCommand.php) | `larament:app/Sync/HeartbeatService.php` |
| **POS Outbox Sender & Retry** | [03-pos-integration-and-transfer.md](./03-pos-integration-and-transfer.md) | N/A (Receiver) | `larament:app/Sync/BatchSender.php`, `larament:app/Sync/SyncOutboxObserver.php`, `larament:app/Sync/Outbox.php` |
| **Ingestion Ingress & Limits** | [04-ingestion-api-and-validation.md](./04-ingestion-api-and-validation.md) | [`IngestBatchController.php`](../../app/Http/Controllers/Api/V1/IngestBatchController.php) | `larament:app/Sync/PlatformClient.php` |
| **Document Validation Rules (16 types)** | [04-ingestion-api-and-validation.md](./04-ingestion-api-and-validation.md) | [`DocumentRules.php`](../../app/Domain/Ingestion/Validation/DocumentRules.php) | `larament:app/Sync/Builders/*` |
| **Idempotence & Version Guard** | [05-concurrency-storage-and-recovery.md](./05-concurrency-storage-and-recovery.md) | [`IngestBatchController.php`](../../app/Http/Controllers/Api/V1/IngestBatchController.php), [`AbstractDocumentHandler.php`](../../app/Domain/Ingestion/Handlers/Concerns/AbstractDocumentHandler.php) | `larament:app/Sync/BatchSender.php` |
| **Distributed Leases & Fencing** | [05-concurrency-storage-and-recovery.md](./05-concurrency-storage-and-recovery.md) | [`ProcessIngestBatch.php`](../../app/Jobs/ProcessIngestBatch.php), [`FencedWorkerException.php`](../../app/Domain/Ingestion/Exceptions/FencedWorkerException.php) | N/A (Platform Internal) |
| **Durable Dirty Dates Outbox** | [05-concurrency-storage-and-recovery.md](./05-concurrency-storage-and-recovery.md) | [`DirtyDateOutbox.php`](../../app/Domain/Ingestion/Support/DirtyDateOutbox.php), `ingest_dirty_dates` table | N/A (Platform Internal) |
| **Automated Crash Recovery** | [05-concurrency-storage-and-recovery.md](./05-concurrency-storage-and-recovery.md) | [`RecoverIngestBatchesCommand.php`](../../app/Console/Commands/RecoverIngestBatchesCommand.php), [`routes/console.php`](../../routes/console.php) | `larament:app/Console/Commands/PlatformBackfillCommand.php` |
| **Mirrored Warehouse Schema** | [06-warehouse-and-aggregation-engine.md](./06-warehouse-and-aggregation-engine.md) | [`2026_11_02_000002_create_warehouse_tables.php`](../../database/migrations/2026_11_02_000002_create_warehouse_tables.php), `app/Models/Warehouse/*` | `larament:database/migrations/*` |
| **Dimensions & Reference Data** | [06-warehouse-and-aggregation-engine.md](./06-warehouse-and-aggregation-engine.md) | [`2026_11_02_000003_create_dimension_tables.php`](../../database/migrations/2026_11_02_000003_create_dimension_tables.php), `DimProduct`, `DimExpenseType` | `larament:app/Models/Product.php`, `ExpenceType.php` |
| **Aggregate Read Models** | [06-warehouse-and-aggregation-engine.md](./06-warehouse-and-aggregation-engine.md) | [`2026_11_02_000004_create_aggregate_tables.php`](../../database/migrations/2026_11_02_000004_create_aggregate_tables.php), `app/Models/Aggregates/*` | N/A (Platform Internal) |
| **Daily Rebuilder Engine** | [06-warehouse-and-aggregation-engine.md](./06-warehouse-and-aggregation-engine.md) | [`BranchDayRebuilder.php`](../../app/Domain/Metrics/Aggregation/BranchDayRebuilder.php), [`RecomputeBranchDay.php`](../../app/Jobs/RecomputeBranchDay.php) | N/A (Platform Internal) |
| **POS Summary Reconciliation** | [06-warehouse-and-aggregation-engine.md](./06-warehouse-and-aggregation-engine.md) | [`Reconciler.php`](../../app/Domain/Metrics/Aggregation/Reconciler.php), [`ReconciliationIssue.php`](../../app/Models/ReconciliationIssue.php) | `larament:app/Sync/Builders/DaySummaryBuilder.php` |
| **MetricsQuery & Branch Scoping** | [07-metrics-query-and-kpi-formulas.md](./07-metrics-query-and-kpi-formulas.md) | [`MetricsQuery.php`](../../app/Domain/Metrics/MetricsQuery.php), [`AccessibleBranches.php`](../../app/Domain/Tenancy/AccessibleBranches.php) | N/A (Reporting Internal) |
| **KPI Catalog ($K01$–$K25$)** | [07-metrics-query-and-kpi-formulas.md](./07-metrics-query-and-kpi-formulas.md) | [`KpiValues.php`](../../app/Domain/Metrics/KpiValues.php), [`Kpi.php`](../../app/Domain/Metrics/Kpi.php) | N/A (Reporting Internal) |
| **Overhead Cost Allocation** | [07-metrics-query-and-kpi-formulas.md](./07-metrics-query-and-kpi-formulas.md) | [`AllocatedCost.php`](../../app/Domain/Metrics/AllocatedCost.php) | N/A (Reporting Internal) |
| **Completeness Engine** | [07-metrics-query-and-kpi-formulas.md](./07-metrics-query-and-kpi-formulas.md) | [`Completeness.php`](../../app/Domain/Metrics/Completeness.php), [`CompletenessResult.php`](../../app/Domain/Metrics/CompletenessResult.php) | N/A (Reporting Internal) |
| **Report Pages (01 to 10)** | [08-reports-catalog.md](./08-reports-catalog.md) | `app/Filament/App/Pages/Reports/*`, `app/Domain/Metrics/Reports/*` | N/A (UI / Presentation) |
| **Array Table Pagination** | [08-reports-catalog.md](./08-reports-catalog.md) | [`ArrayTableRecords.php`](../../app/Filament/App/Widgets/Reports/ArrayTableRecords.php), [`BaseReportTableWidget.php`](../../app/Filament/App/Widgets/Reports/BaseReportTableWidget.php) | N/A (UI / Presentation) |
| **Order Type Sales Summary** | [08-reports-catalog.md](./08-reports-catalog.md) | [`OrderTypeSales.php`](../../app/Domain/Metrics/Reports/OrderTypeSales.php), `OrderTypeSalesWidget.php` | `larament:app/Sync/Builders/OrderBuilder.php` |
| **Target Distribution Math** | [09-targets-and-branch-ranking.md](./09-targets-and-branch-ranking.md) | [`TargetDistributor.php`](../../app/Domain/Targets/TargetDistributor.php), [`CalendarEvent.php`](../../app/Models/CalendarEvent.php) | N/A (Platform Management) |
| **Restaurant Target Bulk Action** | [09-targets-and-branch-ranking.md](./09-targets-and-branch-ranking.md) | [`ListTargets.php`](../../app/Filament/App/Resources/TargetResource/Pages/ListTargets.php), [`TargetResource.php`](../../app/Filament/App/Resources/TargetResource.php) | N/A (Platform Management) |
| **Branch Ranking & Scoring** | [09-targets-and-branch-ranking.md](./09-targets-and-branch-ranking.md) | [`BranchRankingReport.php`](../../app/Domain/Metrics/Reports/BranchRankingReport.php), [`ScoringProfile.php`](../../app/Models/ScoringProfile.php) | N/A (Platform Management) |
| **Tenancy Isolation & Global Scopes** | [10-tenancy-security-and-operations.md](./10-tenancy-security-and-operations.md) | [`OrganizationScope.php`](../../app/Domain/Tenancy/Scopes/OrganizationScope.php), [`BelongsToOrganization.php`](../../app/Domain/Tenancy/Concerns/BelongsToOrganization.php) | N/A (Multi-tenancy) |
| **RBAC Roles & Permissions** | [10-tenancy-security-and-operations.md](./10-tenancy-security-and-operations.md) | [`RolesAndPermissionsSeeder.php`](../../database/seeders/RolesAndPermissionsSeeder.php), `UserPolicy.php` | N/A (Permissions) |
| **Cascading Deletion & Purge** | [10-tenancy-security-and-operations.md](./10-tenancy-security-and-operations.md) | [`2026_11_02_000009_cascade_branch_owned_data.php`](../../database/migrations/2026_11_02_000009_cascade_branch_owned_data.php), [`Branch.php`](../../app/Models/Branch.php), [`DeleteBranchFiles.php`](../../app/Jobs/DeleteBranchFiles.php) | N/A (Platform Lifecycle) |
| **Operations Runbook & Matrix** | [10-tenancy-security-and-operations.md](./10-tenancy-security-and-operations.md) | Section 5 & 6 of `10-tenancy-security-and-operations.md` | Section 5 & 6 |
