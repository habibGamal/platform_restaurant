# AGENTS.md

## Project Overview

**Platform Restaurants** (`platform_restaurant`) is the central multi-unit reporting, data ingestion, and AI analytics hub for restaurant chains, paired with the decentralized **`larament`** POS installed at each branch.

- **Backend:** Laravel 13 (`^13.17`), PHP `^8.3`, Sanctum (`ability:ingest`) for POS API auth, Spatie `laravel-permission` (`^8.3`) for RBAC, Laravel AI SDK (`laravel/ai` `^1.3`), Laravel MCP (`laravel/mcp` `^1.2`), Laravel Passport (`^13.7`), Laravel Fortify (`^1.37.2`).
- **Admin & Analytics UI:** FilamentPHP v5 (`^5.11`) with two panels: `/admin` (platform operators) and `/app` (organization owners, analysts, branch managers).
- **AI Chat UI:** React 19 + Inertia v3 (`^3.0`) + `@assistant-ui/react` + Tailwind CSS v4 + Wayfinder at `/assistant` (controlled by `ASSISTANT_REACT_UI`).
- **Database:** MySQL / MariaDB (`DB_CONNECTION=mysql` in `.env.example` and `phpunit.xml`).

**Arabic UI** (`APP_LOCALE=ar`, RTL). **Currency: EGP**. **Timezone:** `Africa/Cairo`.

## Commands

| Task | Command |
|---|---|
| Full CI gate (frontend check + TS + Pint + PHPStan + PHPUnit) | `composer ci:check` |
| Backend gate (config:clear + Pint + PHPStan + PHPUnit) | `composer test` |
| Fix PHP code style (Pint) | `composer lint` |
| Check PHP code style | `composer lint:check` |
| Static analysis (PHPStan level 7) | `composer types:check` |
| Run all PHPUnit tests | `php artisan test` |
| Single test file / filter | `php artisan test --filter=MetricsQueryTest` |
| Frontend lint/format check (Vite+) | `npm run check` |
| Frontend TypeScript check | `npm run types:check` |
| Frontend tests (Vitest via Vite+) | `npx vp test` |
| Build frontend assets | `npm run build` |
| Dev (HTTP server, Vite, queues incl. `assistant`) | `composer dev` |
| AI eval dry run | `php artisan ai:eval --dry-run` |
| Docs dev server (VitePress on port 5175) | `cd docs && npm run dev` |
| Docs production build | `cd docs && npm run build` (public site without /dev/: `npm run build:public`) |
| Docs hygiene check | `bash scripts/docs-check.sh` |

**Never run `php artisan test` against the dev database** — configure a test database (e.g. `DB_DATABASE=platform_testing` in `phpunit.xml`).

## Architecture

- **Domain modules** — `app/Domain/` (`Tenancy`, `Ingestion`, `Metrics`, `Targets`, `Menu`, `Menu\Intelligence`, `Assistant`, `AiBilling`, `Forecast`)
- **Filament panels** — `app/Filament/Admin/` (`/admin`) and `app/Filament/App/` (`/app`, including `Pages/Reports/`, `Widgets/Reports/`, `Pages/Management/`, `Resources/`, and topbar `ReportScopeSelector`)
- **POS Ingestion API** — `routes/api.php` (`/api/v1/pair`, `/api/v1/pair/rotate`, `/api/v1/ingest/batches`, `/api/v1/heartbeat`) handled by `app/Http/Controllers/Api/V1/`
- **AI & MCP** — `app/Ai/` (`Agents`, `Tools`, `Middleware`, `Gateway`), `app/Http/Controllers/Assistant/`, `routes/assistant/`, `routes/ai.php`, `app/Mcp/Servers/RestaurantServer.php` (`/mcp/restaurants` and `php artisan mcp:start restaurants`), and runtime AI playbooks in `resources/ai/skills/`
- **Data tiers** — Raw mirrored POS tables (`wh_*`, no inter-table FKs), reference dimensions (`dim_*`, `catalog_items`), precomputed daily/hourly/shift aggregates (`agg_*`), and nightly menu intelligence tables (`component_classes`, `category_profiles`, `item_profiles`, `item_period_stats`, `item_affinities`, `item_rankings`, `combo_candidates`, `menu_intelligence_builds`)
- **Queues** — `ingest` (`ProcessIngestBatch`, `DeleteBranchFiles`), `aggregate` (`RecomputeBranchDay`), and `assistant` connection/queue (`RunAssistantQuestion`, `ClassifyItemRoles`, `BuildMenuIntelligence`)
- **Scheduled jobs** (`routes/console.php`) — `ingest:recover` (every minute), `model:prune` for `AiRunEvent` (daily), `ClassifyItemRoles` (04:00 daily), `BuildMenuIntelligence` (04:30 daily)

## Testing

- **Backend (PHPUnit 12)** — `tests/Unit/` and `tests/Feature/` (Pest is not installed). Uses MySQL (`platform_testing` in `phpunit.xml`). Key deterministic fixtures: `Tests\Fixtures\TwoBranchesTwoWeeks` and `Tests\Fixtures\MenuFixture`.
- **Frontend (Vitest)** — `resources/js/**` run via `npx vp test`.
- **AI Evals** — `tests/Evals/questions.v0.php` and `questions.v1.php`, executed via `php artisan ai:eval` (refuses databases whose name does not contain `testing`).
- **Static analysis** — Larastan / PHPStan level 7 (`phpstan.neon`) covering `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`.

## Conventions

- PRs target `main`.
- `composer ci:check` is the pre-push quality gate: `vp check` → `tsc --noEmit` → Pint → PHPStan → PHPUnit.
- Multi-tenancy: tenant models use `BelongsToOrganization` / `OrganizationScope`; report and AI data access goes through `MetricsQuery` + `AccessibleBranches`.
- Timezone & week: `Africa/Cairo`, Egyptian business week Saturday → Friday (`App\Support\Weekday`).
- Demo seed accounts (`DatabaseSeeder`): `operator@example.com` (`operator`), `owner@example.com` (`org_owner`), `analyst@example.com` (`org_analyst`), `manager@example.com` (`branch_manager`), password `password`.

## Documentation

- `docs/` (VitePress) is the single source of truth.
- Any PR that changes behaviour, settings, an API, or deployment must update the matching `docs/` page in the same PR and add a line to `CHANGELOG.md`.
- Only these `.md` locations are allowed: `docs/**` (excluding `docs/node_modules`), `.workspace/**`, `README.md`, `CHANGELOG.md`, `AGENTS.md`, `CLAUDE.md`, `.claude/**`, and `resources/ai/**`.
- Run `bash scripts/docs-check.sh` before pushing.
- The docs theme in `docs/.vitepress/theme/` is shared by the POS, website and platform docs and is edited only in the POS repo. See `docs/dev/style-guide.md` ("Shared theme") and `bash scripts/docs-theme.sh`.

## Workspace

All drafts, plans, specs, tickets, ADRs, research, and handoffs go in `.workspace/` (see `.workspace/index.md`), never in the repo root or `docs/`.

## Agent skills

- Issue tracker -> `.workspace/agents/issue-tracker.md`
- Domain docs (glossary, ADRs) -> `.workspace/agents/domain.md`
- Coding standards -> `.workspace/CODING_STANDARDS.md`
