# Product Overview

This document provides a high-level overview of **`platform-restaurants`**, its target audience, actor roles, core capabilities, key business rules, vocabulary, and operational boundaries.

---

## What it is

**`platform-restaurants`** is a central multi-unit data ingestion, financial reporting, and operational analytics platform for restaurant chains. Restaurant brands operate decentralized point-of-sale systems (such as the paired **`larament`** POS) in physical branches, where local connectivity issues and offline operation are common. The platform acts as the central cloud hub: it cryptographically authenticates terminals, ingests transactional batches (orders, inventory movements, staff shifts, cash expenses), normalizes the data into a high-performance mirrored warehouse, precomputes daily financial and operational metrics, provides 10 executive analytics dashboards, and provides an intelligent multi-agent AI assistant for real-time natural language business inquiries.

---

## Actors and roles

| Actor | What they do | How they access it | Permission source |
|---|---|---|---|
| **Platform Super Admin** | Provisions organizations, manages system health, executes global migrations | Web Admin Panel (`/admin`) | Spatie Role: `Super Admin` via [`AdminPanelProvider.php`](../app/Providers/Filament/AdminPanelProvider.php) |
| **Organization Owner** | Manages franchise holdings, configures AI token budgets, sets chain targets, views all reports | Web App Panel (`/app`) | Spatie Role: `Organization Owner` via [`AppPanelProvider.php`](../app/Providers/Filament/AppPanelProvider.php) |
| **Restaurant Manager** | Reviews sales and labor performance for a specific restaurant brand | Web App Panel (`/app`) | Spatie Role: `Restaurant Manager` |
| **Branch Manager** | Generates pairing codes, monitors daily store sales, reconciles cashier shifts | Web App Panel (`/app`) | Spatie Role: `Branch Manager` scoped via [`AccessibleBranches.php`](../app/Domain/Tenancy/AccessibleBranches.php) |
| **Financial Analyst** | Conducts LFL comparisons, reviews P&L margin ladders, interacts with AI assistant | Web App Panel (`/app`) | Spatie Role: `Analyst` with permission `analytics.view` |
| **Branch POS Terminal** | Syncs transactions, transmits heartbeats, executes remote maintenance commands | Ingestion HTTP API (`/api/v1/*`) | Laravel Sanctum token with ability `ingest` |

---

## Capability map

- **POS Integration & Ingestion:**
  - [POS Pairing & Heartbeat](features/pos-pairing-and-heartbeat.md): One-time pairing code handshake, token rotation, and telemetry heartbeat.
  - [Batch Ingestion Pipeline](features/batch-ingestion.md): High-throughput gzip batch API handling 16 document schemas with idempotency.
  - [Crash Recovery & Worker Fencing](features/crash-recovery-and-fencing.md): Monotonic fencing tokens and automated background batch recovery.
- **Data Warehouse & Aggregations:**
  - [Warehouse, Dimensions & Aggregates](features/warehouse-and-aggregates.md): Mirrored transaction tables, reference dimensions, daily aggregate rebuilder, and POS reconciliation.
  - [Metrics Query Engine & KPI Catalog](features/metrics-query-and-kpis.md): Unified query interface, 52-week like-for-like comparison, and $K01$–$K25$ KPI formulas.
- **Analytics, Planning & Governance:**
  - [Production Analytics Reports](features/analytics-reports.md): 10 dedicated Filament reporting dashboards with in-memory array table pagination.
  - [Targets Planning & Branch Ranking](features/targets-and-ranking.md): History-based weekday target distribution and 7-KPI multi-criteria branch ranking.
  - [Tenancy & Access Control](features/tenancy-and-access-control.md): Organization isolation, restaurant hierarchy, and cascading data deletion.
- **AI & Menu Intelligence:**
  - [AI Restaurant Analyst Assistant](features/ai-assistant.md): Multi-agent conversational assistant with live SSE work logs and SQL-safe metrics query tools.
  - [Menu Engineering & Item Roles](features/menu-engineering-and-roles.md): Automated BCG menu matrix classification (Stars, Plowhorses, Puzzles, Dogs) and basket analysis.

---

## Key business rules

1. **Monotonic Entity Versioning:** Updates to raw warehouse entities must present a strictly greater `version` integer than currently recorded; out-of-order or stale transmissions are rejected ([details](features/batch-ingestion.md)).
2. **Immutable Audit Lineage:** Raw POS documents staged in `wh_*` tables are never physically overwritten or deleted; updates insert new revisions or set `is_deleted = true` tombstones ([details](features/warehouse-and-aggregates.md)).
3. **Like-for-Like (LFL) 364-Day Alignment:** Historical year-over-year comparisons shift exactly 52 weeks (364 days), ensuring that day-of-week retail patterns (e.g., Friday night rushes) are compared against the identical weekday of the previous year ([details](features/metrics-query-and-kpis.md)).
4. **Corporate Overhead Distribution:** Centralized costs (HQ salaries, shared marketing campaigns, brand licensing) are apportioned across operating branches proportionally based on net sales contribution ([details](features/metrics-query-and-kpis.md)).
5. **Deterministic Target Weighting:** Monthly branch sales targets are distributed across individual days based on normalized 8-week historical sales weights, with dates flagged as anomalous in [`CalendarEvent`](../app/Models/CalendarEvent.php) excluded from the baseline ([details](features/targets-and-ranking.md)).
6. **Strict Multi-Tenant Scoping:** All Eloquent models inherit [`OrganizationScope`](../app/Domain/Tenancy/Scopes/OrganizationScope.php); no user can query or mutate records outside their authorized organization ([details](features/tenancy-and-access-control.md)).

---

## Glossary

| Term | Meaning in Domain | Implementing Symbol |
|---|---|---|
| **Branch** | A physical operating restaurant location with active POS terminals | [`Branch`](../app/Models/Branch.php) |
| **Restaurant** | A distinct brand or business concept owned by an organization | [`Restaurant`](../app/Models/Restaurant.php) |
| **Organization** | The top-level corporate or franchise holding entity | [`Organization`](../app/Models/Organization.php) |
| **Ingest Batch** | A compressed HTTP envelope of transactional POS documents | [`IngestBatch`](../app/Models/IngestBatch.php) |
| **Dirty Date** | A branch calendar date whose raw records changed, requiring aggregate recomputation | [`DirtyDateOutbox`](../app/Domain/Ingestion/Support/DirtyDateOutbox.php) |
| **Daily Rebuilder** | The aggregation engine that transforms raw rows into summary metrics | [`BranchDayRebuilder`](../app/Domain/Metrics/Aggregation/BranchDayRebuilder.php) |
| **Prime Cost** | The sum of food costs (COGS) and direct store hourly labor costs ($K15$) | [`KpiValues::primeCost`](../app/Domain/Metrics/KpiValues.php) |
| **LFL (Like-for-Like)** | Compares identical operating units over 364-day weekday-aligned periods | [`Period::toLikeForLike`](../app/Domain/Metrics/Period/Period.php) |
| **Scoring Profile** | Configurable weighting template for ranking branches across 7 KPIs | [`ScoringProfile`](../app/Models/ScoringProfile.php) |
| **Item Role** | BCG menu classification quadrant (Star, Plowhorse, Puzzle, Dog) | [`ItemRoleAssignment`](../app/Models/ItemRoleAssignment.php) |

---

## Scope boundaries

- **What the system does:** Centralizes ingestion from multiple remote POS terminals, provides multi-unit reporting and KPI computation, maintains warehouse data lineage, tracks targets, and hosts an AI assistant.
- **What the system does NOT do:**
  - It is **not** an in-store order punch terminal or kitchen display system (KDS); order entry and kitchen routing happen on local branch POS terminals (`larament`).
  - It does **not** process live payment card transactions or manage payment gateway merchant accounts; POS terminals finalize payments locally and transmit audit totals.
  - It does **not** manage external supplier catalogs or purchase order EDI routing; supplier purchase invoices are recorded after-the-fact for inventory cost analysis.
