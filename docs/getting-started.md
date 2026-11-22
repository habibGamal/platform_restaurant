# Getting Started

This guide walks a developer through cloning, configuring, migrating, running, and verifying **`platform-restaurants`** locally from scratch.

---

## Prerequisites

| Tool | Required Version | Source of Truth |
|---|---|---|
| **PHP** | `^8.2` | [`composer.json`](../composer.json#L15) |
| **Composer** | `^2.5` | Standard PHP dependency manager |
| **Node.js** | `^20.0` | [`package.json`](../package.json) |
| **npm** | `^10.0` | [`package-lock.json`](../package-lock.json) |
| **Database** | MySQL `^8.0`, PostgreSQL `^15.0`, or SQLite `^3.35` | [`.env.example`](../.env.example#L13-L17) |
| **Redis** | `^6.0` (Optional for local queue/cache; `sync`/`database` supported) | [`config/database.php`](../config/database.php) |

---

## 1. Install

Clone the repository and install both PHP and JavaScript dependencies:

```bash
# Clone the repository
git clone <repository-url> platform-restaurants
cd platform-restaurants

# Install PHP dependencies
composer install

# Install Node dependencies
npm install
```

---

## 2. Configure

Copy the example environment configuration file and generate the application encryption key:

```bash
# Copy example environment configuration
cp .env.example .env

# Generate unique Laravel application key
php artisan key:generate
```

### Essential Local Settings in `.env`

For local development using SQLite:
```dotenv
APP_NAME="Platform Restaurants"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
# When using SQLite, touch the database file:
# touch database/database.sqlite

QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
```

For development using MySQL / PostgreSQL:
```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=platform_restaurants
DB_USERNAME=root
DB_PASSWORD=secret
```

See [Configuration](configuration.md) for full descriptions of all environment variables.

---

## 3. Prepare Data

Run database migrations and load baseline seed data:

```bash
# Create SQLite file if using SQLite
touch database/database.sqlite

# Run all migrations and seed initial roles, permissions, and test tenants
php artisan migrate --seed
```

### What the Seeders Create

The database seeders ([`DatabaseSeeder.php`](../database/seeders/DatabaseSeeder.php), [`RolesAndPermissionsSeeder.php`](../database/seeders/RolesAndPermissionsSeeder.php)) create:
- **Default Permissions & Roles:** `Super Admin`, `Organization Owner`, `Restaurant Manager`, `Branch Manager`, `Analyst`.
- **Default Super Admin:**
  - Email: `admin@platform.test`
  - Password: `password`
  - Access: Full access to `/admin` and `/app`.
- **Sample Organization & Brands:** A demo holding group with two restaurant brands ("Burger Co." and "Pizza Kitchen") and four operating branches with historical test orders.

---

## 4. Run

Start the application services. In development, you can run all processes using Composer's dev script or run them in separate terminal tabs:

### Option A: Using the Composer Dev Runner
```bash
composer run dev
```

### Option B: Running Individual Processes

**Terminal 1 — Local HTTP Web Server:**
```bash
php artisan serve --port=8000
```

**Terminal 2 — Vite Frontend Development Server:**
```bash
npm run dev
```

**Terminal 3 — Background Queue Worker (Optional if `QUEUE_CONNECTION=sync`):**
```bash
php artisan queue:work --queue=ingest,aggregates,assistant,default
```

Once running:
- Open the tenant App Panel at **`http://localhost:8000/app`**
- Open the platform Admin Panel at **`http://localhost:8000/admin`**
- Sign in using `admin@platform.test` / `password`. You will see the **Overview Dashboard** loaded with demo branch analytics.

---

## 5. Verify

Verify that your installation is complete and healthy by running the test suite:

```bash
php artisan test --filter=ExampleTest
```

Expected output:
```text
   PASS  Tests\Unit\ExampleTest
  ✓ that true is true

   PASS  Tests\Feature\ExampleTest
  ✓ the application returns a successful response

  Tests:    2 passed (2 assertions)
  Duration: 0.15s
```

To run feature test suites verifying the core reporting and tenancy domains:
```bash
php artisan test --filter=Tenancy
php artisan test --filter=Metrics
```

---

## Common Setup Problems

| Symptom | Cause | Fix |
|---|---|---|
| `Database file at ... does not exist` | SQLite file missing | Run `touch database/database.sqlite` and re-run `php artisan migrate` |
| Vite assets not loading / missing CSS | Frontend assets have not been compiled | Run `npm run build` or start Vite dev server via `npm run dev` |
| `Class Spatie\Permission\... not found` | Composer autoload cache is stale | Run `composer dump-autoload` |
| `Unauthenticated` on `/app` | Session cookie domain mismatch | Ensure `APP_URL` in `.env` matches the browser URL (`http://localhost:8000`) |

---

## Next Steps

1. Read the [Architecture Guide](architecture.md) to understand runtime building blocks and cross-cutting systems.
2. Review the [Data Model](data-model.md) to understand the relational and mirrored warehouse schemas.
3. Explore the [Feature Catalog](features/README.md) and dive into specific feature guides.
4. Launch the interactive browser documentation site at any time via `php artisan docs:serve` or navigate to `/docs` in the app.
