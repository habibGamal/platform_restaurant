# Overview & Stack

**Platform Restaurants** (`platform_restaurant`) is the central multi-unit ingestion, financial reporting, and AI analytics hub for restaurant chains. Each physical branch operates its own decentralized **`larament`** POS behind local NAT and pushes transactional batches outbound to this platform.

---

## Technology Stack

Verified against `composer.json`, `package.json`, and `phpunit.xml`:

| Layer | Technology | Version |
|---|---|---|
| **Language** | PHP | `^8.3` |
| **Framework** | Laravel | `^13.17` |
| **Admin & Analytics UI** | FilamentPHP (Admin `/admin` & App `/app`) | `^5.11` |
| **AI Chat UI** | React 19, Inertia.js, `@assistant-ui/react`, `ai` v7, Tailwind v4, Wayfinder | `inertia ^3.0`, `react ^19.2` |
| **Auth & Tokens** | Sanctum (POS API), Fortify + Passkeys (web), Passport (MCP OAuth) | `sanctum ^4.0`, `passport ^13.7` |
| **Authorization** | Spatie `laravel-permission` | `^8.3` |
| **AI & MCP** | `laravel/ai`, `laravel/mcp` | `^1.3`, `^1.2` |
| **Database** | MySQL / MariaDB (portable SQL; no Postgres-only syntax) | `DB_CONNECTION=mysql` |
| **Testing & QA** | PHPUnit 12, Larastan/PHPStan level 7, Laravel Pint, Vite+ (`vp check`, `vp test`) | `phpunit ^12.5.23` |

---

## Actors & Roles

Defined in `database/seeders/RolesAndPermissionsSeeder.php`:

| Role | Panel | Scope | Primary Responsibilities |
|---|---|---|---|
| `operator` | `/admin` | Cross-organization | Manage organizations, restaurants, branches, pairing/access, and tenant users (`branches.manage`). |
| `org_owner` | `/app` | Entire organization | Full analytics, AI assistant (`ai.view`, `ai.manage`), targets, users, scoring profiles, expense mappings, menu intelligence review. |
| `org_analyst` | `/app` | Entire organization | Read-only access to all 10 reports, group benchmarks, exports, and AI assistant (`analytics.view`, `analytics.view_group`, `analytics.view_other_branch_names`, `ai.view`, `export`). |
| `branch_manager` | `/app` | Assigned branches only (`user_branch_access`) | View reports and export for assigned branches (`analytics.view`, `alerts.acknowledge`, `export`); sibling branch names and group totals are masked unless granted. |
| **Branch POS (`larament`)** | `/api/v1/*` | Single branch (`ability:ingest`) | Pair via 8-char code, push gzip document batches, send 5-minute heartbeats, and execute `resync` / `rotate_token` commands. |

---

## Scope Boundaries

- **What the platform does:** Authenticates branch POS terminals, persists mirrored raw warehouse records (`wh_*`), builds precomputed daily/hourly/shift aggregates (`agg_*`), reconciles against POS `day_summary` checksums, serves 10 Filament analytics reports, manages monthly targets and branch rankings, precomputes menu intelligence, and hosts a grounded multi-agent AI analyst (web chat + MCP).
- **What the platform does NOT do:**
  - It is not a live POS cashier terminal, kitchen display system (KDS), or receipt printer manager (those run locally in `larament`).
  - It does not process live card payments or manage online storefront checkouts.
  - **Currently open / unbuilt subsystems** tracked as specs in `.workspace/specs/`: rule-based/anomaly Alerts engine, two-way WhatsApp channel & daily briefs, standalone deterministic analyzers (`GrowthAnalyzer`, `AnomalyDetector`, `ContributionAnalyzer`, `CostRatioAnalyzer`, `MaterialNeedsForecaster`), item repricing suggestion workflow (`ai_menu_suggestions`), and intraday hourly returns cutoff.
