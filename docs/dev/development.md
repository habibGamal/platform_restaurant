# Development Guide

---

## 1. Local Setup

```bash
composer setup
```

`composer setup` runs `composer install`, creates `.env` from `.env.example` if missing, generates `APP_KEY`, runs `php artisan migrate --force`, runs `npm install`, and runs `npm run build`.

To load demo data (`operator@example.com`, `owner@example.com`, `analyst@example.com`, `manager@example.com`, password `password`):

```bash
php artisan db:seed
```

Start all local dev services (HTTP server, Vite, queue workers including `assistant-queue` configured in `AppServiceProvider::configureDevProcesses()`):

```bash
composer dev
```

For local SSE streaming with `php artisan serve`, set `PHP_CLI_SERVER_WORKERS=4` in `.env` so the PHP CLI server does not block concurrent requests while a stream is open.

---

## 2. Day-to-Day Commands

| Task | Command | Notes |
|---|---|---|
| Full CI check | `composer ci:check` | `npm run check` -> `npm run types:check` -> `composer test` |
| Backend quality gate | `composer test` | `config:clear` -> `pint --parallel --test` -> `phpstan analyse` -> `php artisan test` |
| Format PHP | `composer lint` | Laravel Pint (`pint.json`, preset `laravel`) |
| Static analysis | `composer types:check` | PHPStan / Larastan level 7 (`phpstan.neon`) |
| Frontend check & fix | `npm run check` / `npm run check:fix` | Vite+ (`vp check`) |
| Frontend TypeScript | `npm run types:check` | `tsc --noEmit` |
| Build frontend assets | `npm run build` | Builds Filament app theme & React assistant UI |
| Docs dev server | `cd docs && npm run dev` | VitePress dev server on port `5175` |
| Docs check | `bash scripts/docs-check.sh --build` | Validates Markdown allow-list, Arabic MSA check, and VitePress build |

---

## 3. Coding & Architecture Conventions

- **Tenancy:** Every tenant model must use `BelongsToOrganization` (`OrganizationScope`). Every analytics/AI query must scope branches via `MetricsQuery` / `AccessibleBranches`.
- **SQL portability:** Keep queries compatible with both MySQL 8 and MariaDB >= 10.4 (`DB::table()->upsert()`, PHP-side version guards, explicit timestamp defaults, standard `json` columns).
- **Filament App theme:** Report and management Blade views rely on `resources/css/filament/app/theme.css`; run `npm run build` after modifying Filament styling or React components so `public/build` stays current.
- **Drafts & specs:** Keep work-in-progress specs, ADRs, and research under `.workspace/`, never in `docs/` or repo root. Update `docs/` and `CHANGELOG.md` in the same PR when behaviour changes.
