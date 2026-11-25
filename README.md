# Platform Restaurants

Central multi-unit reporting, POS data ingestion, and AI analytics hub for restaurant chains, paired with the decentralized **`larament`** POS.

Built with **Laravel 13**, **Filament v5**, **React 19 + Inertia v3** (AI assistant chat UI), **Tailwind CSS v4**, and **MySQL / MariaDB**.

## Quick Commands

```bash
# Install dependencies & prepare local app
composer setup

# Start local dev servers (HTTP, Vite, queues including assistant)
composer dev

# Run full quality gate (Vite+ check, TypeScript, Pint, PHPStan level 7, PHPUnit)
composer ci:check
```

## Documentation

Project documentation lives in `docs/` as a VitePress site (Arabic default at `/`, English developer handbook at `/dev/`). Read and preview it locally on port 5175 with:

```bash
cd docs && npm install && npm run dev
```

Build and validate docs locally:

```bash
bash scripts/docs-check.sh --build
```

See [CHANGELOG.md](CHANGELOG.md) for recent changes and [AGENTS.md](AGENTS.md) for codebase conventions.
