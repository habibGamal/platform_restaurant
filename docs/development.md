# Development Guide

This guide describes repository structure, day-to-day commands, code style conventions, static analysis, the worked walkthrough for adding a new feature, testing suites, and CI workflows for **`platform-restaurants`**.

---

## Repository Layout

| Path | Contents |
|---|---|
| [`app/Domain`](../app/Domain) | Core business logic domains: `Tenancy`, `Ingestion`, `Metrics`, `Targets`, `Menu` |
| [`app/Ai`](../app/Ai) | Multi-agent coordinators, specialists, gateway, middleware, and tools |
| [`app/Filament/Admin`](../app/Filament/Admin) | Super-administrator resources and pages for global platform management |
| [`app/Filament/App`](../app/Filament/App) | Multi-tenant dashboards, management views, and 10 analytical report pages |
| [`app/Http/Controllers/Api/V1`](../app/Http/Controllers/Api/V1) | POS ingress endpoints (pairing, token rotation, batch ingestion, heartbeat) |
| [`app/Models`](../app/Models) | Eloquent domain entities, raw warehouse tables (`wh_*`), aggregates (`agg_*`) |
| [`database/migrations`](../database/migrations) | Schema definitions for multi-tenancy, warehouse, dimensions, and aggregates |
| [`database/seeders`](../database/seeders) | Seeders for RBAC roles, permissions, and test tenant fixtures |
| [`resources/js`](../resources/js) | React frontend components, actions, and layouts |
| [`resources/css/filament/app`](../resources/css/filament/app) | Custom emerald theme styles for Filament App Panel |
| [`tests`](../tests) | Pest / PHPUnit test suites: `Unit`, `Feature`, `Fixtures`, `Evals` |

Vendored and generated paths that must never be edited by hand: `vendor/`, `node_modules/`, `public/build/`.

---

## Day-to-Day Commands

| Task | Command | Notes |
|---|---|---|
| Run full application | `composer run dev` | Starts server, Vite, and queue worker |
| Run all automated tests | `composer run test` | Clears config cache, runs Pint, PHPStan, and PHPUnit |
| Run specific test suite | `php artisan test --filter=Report` | Fast execution for a single feature or domain |
| Browse documentation | `php artisan docs:serve` | Starts local docs server and opens browser |
| Run code style linter | `composer run lint` | Formats PHP code using Laravel Pint in parallel |
| Run type checker | `composer run types:check` | Analyzes code with PHPStan |
| Build frontend assets | `npm run build` | Compiles production Vite CSS & JS bundles |
| Create a new migration | `php artisan make:migration <name>` | Generates timestamped migration stub |
| Seed database | `php artisan db:seed` | Populates default roles, users, and sample stores |

---

## Conventions

- **Code Style:** Enforced by Laravel Pint ([`pint.json`](../pint.json)). Run `composer run lint` before committing.
- **Static Typing & Analysis:** PHPStan level configured in [`phpstan.neon`](../phpstan.neon). Run `composer run types:check` to verify strict typing.
- **Commit Messages:** Follow Conventional Commits format: `<type>(<scope>): <short description>`. Examples: `feat(ingestion): add recipe handler`, `fix(metrics): correct LFL leap year shift`.
- **Tenancy Scoping:** Any model storing organization-specific data **must** implement [`BelongsToOrganization`](../app/Domain/Tenancy/Concerns/BelongsToOrganization.php) to automatically bind [`OrganizationScope`](../app/Domain/Tenancy/Scopes/OrganizationScope.php).

---

## Adding a Feature

Walkthrough: Adding a new POS Document Handler to the ingestion pipeline.

1. **Create the Raw Warehouse Migration:**
   Create a migration in `database/migrations` adding the raw table (e.g., `wh_reservations`), including columns `branch_id`, `pos_reservation_id`, `version`, `is_deleted`, and timestamps.
2. **Define the Warehouse Eloquent Model:**
   Create model in `app/Models/Warehouse/WhReservation.php` extending `Model` and using `BelongsToOrganization`.
3. **Implement the Document Handler:**
   Create `app/Domain/Ingestion/Handlers/ReservationHandler.php` extending [`AbstractDocumentHandler`](../app/Domain/Ingestion/Handlers/Concerns/AbstractDocumentHandler.php):
   ```php
   namespace App\Domain\Ingestion\Handlers;

   use App\Domain\Ingestion\Handlers\Concerns\AbstractDocumentHandler;

   class ReservationHandler extends AbstractDocumentHandler
   {
       public function handle(array $document, int $branchId): void
       {
           // Validate version guard & upsert into wh_reservations
       }
   }
   ```
4. **Register Document Validation Rules:**
   Add validation rules for document type `'reservation'` in [`DocumentRules::rulesFor`](../app/Domain/Ingestion/Validation/DocumentRules.php).
5. **Add Automated Feature Tests:**
   Add tests in `tests/Feature/Ingestion/ReservationHandlerTest.php` testing normal ingestion, duplicate version rejection, and tombstone handling.
6. **Update Documentation:**
   Update the [Batch Ingestion](features/batch-ingestion.md) guide and [Changelog](../CHANGELOG.md).

---

## Testing

| Suite | Directory | Command | Dependencies | Runs in CI? |
|---|---|---|---|---|
| **Unit Tests** | [`tests/Unit`](../tests/Unit) | `php artisan test --testsuite=Unit` | In-memory; fast | Yes |
| **Feature Tests** | [`tests/Feature`](../tests/Feature) | `php artisan test --testsuite=Feature` | SQLite / MySQL test DB | Yes |
| **Menu Intelligence Tests** | [`tests/Feature/Menu/Intelligence`](../tests/Feature/Menu/Intelligence) | `php artisan test --filter=Intelligence` | SQLite / MySQL test DB | Yes |
| **AI Evaluation Benchmark** | [`tests/Evals`](../tests/Evals) | `php artisan ai:eval` | Requires configured LLM mock/API | Optional / manual |

### Test Fixtures

- [`TwoBranchesTwoWeeks`](../tests/Fixtures/TwoBranchesTwoWeeks.php): Seeds a deterministic two-week dataset across two restaurant branches with known orders, clock-ins, and food costs. Use this fixture to verify KPI calculation formulas.
- [`MenuFixture`](../tests/Fixtures/MenuFixture.php): Populates products across standard BCG quadrants for testing menu engineering logic.

---

## Debugging

- **Application Logs:** Stream local logs via `tail -f storage/logs/laravel.log`.
- **Tinker REPL:** Interactively query models and test calculations:
  ```bash
  php artisan tinker
  ```
- **Inspect Queue Backlogs:** Check pending jobs on Redis:
  ```bash
  php artisan queue:monitor redis:ingest,redis:aggregates,redis:assistant
  ```
- **Clear Application Caches:**
  ```bash
  php artisan optimize:clear
  ```

---

## Continuous Integration

The repository uses GitHub Actions configured in [`.github/workflows/tests.yml`](../.github/workflows/tests.yml):

| Trigger | Steps Run | Success Criteria |
|---|---|---|
| Push to `main` or Pull Request | `composer setup && composer ci:check` | Lint clean (Pint), types check (PHPStan), and all unit/feature tests pass |
