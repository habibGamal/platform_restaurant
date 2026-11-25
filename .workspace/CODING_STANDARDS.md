# Coding Standards

Conventions observed in `AGENTS.md`, config files, and the `platform-restaurants` codebase:

- **Pre-push CI gate:** `composer ci:check` (`npm run check` -> `npm run types:check` -> `composer test`).
- **Backend gate:** `composer test` runs `php artisan config:clear`, `pint --parallel --test`, `phpstan analyse`, and `php artisan test`.
- **PHP formatting:** Laravel Pint with the `"laravel"` preset (`pint.json`). Run `composer lint` (`pint --parallel`).
- **Static analysis:** PHPStan / Larastan **level 7** (`phpstan.neon`) covering `app/`, `bootstrap/app.php`, `config/`, `database/`, and `routes/`.
- **Testing (Backend):** PHPUnit 12 (`phpunit.xml` — Pest is not installed) using MySQL (`DB_DATABASE=platform_testing`). Never run tests against the development database. Use `Tests\Fixtures\TwoBranchesTwoWeeks` and `Tests\Fixtures\MenuFixture` for deterministic assertions.
- **Frontend & Chat UI:** React 19, TypeScript (`tsconfig.json`), Vite+ (`vp check`, `vp build`, `vp test`), Tailwind CSS v4, `@assistant-ui/react`, `ai` v7 (`ai-sdk/v6` format), Wayfinder route helpers. Use logical CSS utilities (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`, `text-start`, `text-end`) for RTL compatibility.
- **Filament v5 layout:** Admin operator panel resources live under `app/Filament/Admin/`; tenant app panel pages, reports, widgets, and management resources live under `app/Filament/App/` with its dedicated theme (`resources/css/filament/app/theme.css`).
- **Tenancy & read boundary:** All tenant models use `BelongsToOrganization` (`OrganizationScope`). Reporting and AI tools must read aggregates through `MetricsQuery` + `AccessibleBranches` and enforce `can('use-assistant')` / `AiAccess`.
- **Database portability:** Target MySQL 8 / MariaDB >= 10.4 compatibility (use Laravel `upsert`, PHP-side version guards, explicit timestamp defaults rather than implicit MariaDB `ON UPDATE`, and standard `json` columns).
- **Locale defaults:** `APP_LOCALE=ar` (RTL), currency `EGP`, timezone `Africa/Cairo`, week start Saturday (`App\Support\Weekday`).
