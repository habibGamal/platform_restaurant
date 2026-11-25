# Testing

Platform Restaurants uses **PHPUnit 12** (Pest is not installed) for backend tests against a dedicated MySQL database, **Vitest** (`vp test`) for React assistant UI tests, and an artisan **AI eval harness** (`ai:eval`).

---

## 1. Backend Tests (PHPUnit)

Configured in `phpunit.xml`:
- `DB_CONNECTION=mysql`
- `DB_DATABASE=platform_testing`
- Suites: `Unit` (`tests/Unit`) and `Feature` (`tests/Feature`).

```bash
# Full backend suite (config:clear + Pint + PHPStan + PHPUnit)
composer test

# Run a targeted suite or test file
php artisan test --filter=MetricsQueryTest
php artisan test tests/Feature/Ingestion
```

> [!WARNING]
> Never point `DB_DATABASE` at the development database when running `php artisan test` or `php artisan ai:eval` — tests use `RefreshDatabase` and destructive resets.

### Deterministic Test Fixtures
- `Tests\Fixtures\TwoBranchesTwoWeeks`: Seeds an organization (`R`), branches `A` (busy) and `B` (quiet) over 14 closed days (`2026-10-03`–`2026-10-16`) with known orders, returns, cancelled orders, expenses (including `labor` with `avg_month_rate = 30000`), waste, stocktaking, one missing day on branch `B` (`2026-10-10`), and a deliberate `day_summary` mismatch on `B` (`2026-10-14`).
- `Tests\Fixtures\MenuFixture`: Seeds 60 complete days of menu items across BCG quadrants for menu engineering and intelligence tests.

---

## 2. Frontend Tests (Vitest via Vite+)

Frontend tests for the React assistant chat UI live alongside `resources/js/**` and run via Vite+:

```bash
npx vp test
```

Type-checking and lint/formatting checks:

```bash
npm run types:check
npm run check
```

---

## 3. AI Evaluation Harness (`php artisan ai:eval`)

`App\Console\Commands\AiEval` evaluates `RestaurantAnalyst` against `tests/Evals/questions.v0.php` and `questions.v1.php`:

```bash
# Inspect dataset cases without calling any LLM
php artisan ai:eval --dry-run

# Run against a test database (database name MUST contain "testing")
DB_DATABASE=platform_testing php artisan ai:eval --model=<model> --provider=<provider>
```

`AiEval` refuses to execute unless the active database name contains `"testing"`. `EvalReport` checks numeric grounding (`>= 98%`), permission adherence (`== 100%`), and tool selection (`>= 90%`).
