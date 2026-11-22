# Platform Restaurants

Central reporting, multi-unit analytics, and data ingestion hub for restaurant chains, designed to integrate with decentralized point-of-sale systems (including the paired **`larament`** POS).

Built with **Laravel 12**, **Filament v3**, **Inertia.js + React**, **Tailwind CSS**, and **PostgreSQL / MySQL**.

---

## Documentation

The complete developer handbook and architectural specifications are located in the **[Documentation Hub](docs/README.md)**:

| Guide | Description |
|---|---|
| **[Handbook Index](docs/README.md)** | Start here: system at a glance, reading paths, and documentation index |
| **[Product Overview](docs/overview.md)** | Domain model, actors, business rules, glossary, and scope boundaries |
| **[Getting Started](docs/getting-started.md)** | Local development environment setup, database seeding, and verification |
| **[Architecture](docs/architecture.md)** | System context, runtime building blocks, request lifecycle, and cross-cutting concerns |
| **[Data Model](docs/data-model.md)** | Relational database schema, ER diagrams, raw warehouse tables, and state lifecycles |
| **[Interfaces & APIs](docs/interfaces.md)** | Ingestion HTTP endpoints, Sanctum authentication, CLI commands, and webhooks |
| **[Configuration](docs/configuration.md)** | Environment variables, defaults, tenant overrides, and secret management |
| **[Development Guide](docs/development.md)** | Conventions, repository layout, testing suites, and adding new features |
| **[Feature Catalog](docs/features/README.md)** | Comprehensive index of platform capabilities and domain guides |
| **[Deployment](docs/deployment.md)** | Topology, background workers, release pipelines, and rollback runbooks |
| **[Operations & Troubleshooting](docs/operations.md)** | Health checks, queue diagnostics, crash recovery, and incident runbooks |
| **[Documentation Coverage](docs/coverage.md)** | Verification matrices, test status, and open engineering questions |
| **[Changelog](CHANGELOG.md)** | Detailed chronological record of features, fixes, and upgrade notes |

---

## Quick Start

```bash
# 1. Clone & install dependencies
git clone <repository-url> platform-restaurants
cd platform-restaurants
composer install
npm install

# 2. Configure environment
cp .env.example .env
php artisan key:generate

# 3. Database migrations & seeding
php artisan migrate --seed

# 4. Build frontend assets & run dev server
npm run build
php artisan dev
```

For detailed setup steps and common troubleshooting scenarios, consult the **[Getting Started Guide](docs/getting-started.md)**.
