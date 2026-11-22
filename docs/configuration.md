# Configuration

This document provides a comprehensive guide to environment variables, runtime configuration options, admin database settings, secrets handling, and environment-specific behaviors for **`platform-restaurants`**.

---

## How Configuration is Loaded

Configuration is loaded through standard Laravel 12 mechanisms:
1. **Environment Variables:** Loaded from the `.env` file at application root via `vlucas/phpdotenv`.
2. **Configuration Files:** Defined in [`config/*.php`](../config). In production, running `php artisan config:cache` serializes these files into a single cached file for performance.
3. **Database Tenant Settings:** Dynamic settings such as AI provider configurations ([`AiSetting`](../app/Models/AiSetting.php)) and ranking scoring profiles ([`ScoringProfile`](../app/Models/ScoringProfile.php)) are stored in database tables and edited via the Filament UI.
4. **Precedence:** Database tenant setting > Environment variable > Hardcoded default in `config/*.php`.

> [!IMPORTANT]
> When updating `.env` in production or staging, you **must** execute `php artisan config:clear && php artisan config:cache` for changes to take effect.

---

## Environment Variables

### Application Core

| Name | Required | Default | Purpose | Notes |
|---|---|---|---|---|
| `APP_NAME` | No | `"Platform Restaurants"` | Display name in emails, UI headers, and metadata | Plain text |
| `APP_ENV` | Yes | `production` | Environment name (`local`, `staging`, `production`) | Controls debug behaviors |
| `APP_KEY` | Yes | *None* | 32-character AES encryption key for sessions and tokens | **Secret**; generate via `php artisan key:generate` |
| `APP_DEBUG` | Yes | `false` | Enables detailed stack traces on HTTP error responses | **Must be false in production** |
| `APP_URL` | Yes | `http://localhost:8000` | Canonical base URL used for email links and asset loading | Full URL including protocol |
| `APP_TIMEZONE` | No | `UTC` | Default server application timezone | Database records stored in UTC |

### Database & Storage

| Name | Required | Default | Purpose | Notes |
|---|---|---|---|---|
| `DB_CONNECTION` | Yes | `mysql` | Primary database driver (`mysql`, `pgsql`, `sqlite`) | Source: [`config/database.php`](../config/database.php) |
| `DB_HOST` | Conditional | `127.0.0.1` | Database server host address | Not required for SQLite |
| `DB_PORT` | Conditional | `3306` | Database connection port | Default `3306` (MySQL) or `5432` (Postgres) |
| `DB_DATABASE` | Yes | `platform_restaurants` | Database name or SQLite file path | Synthetic name |
| `DB_USERNAME` | Conditional | `root` | Database authentication username | Not required for SQLite |
| `DB_PASSWORD` | Conditional | *None* | Database authentication password | **Secret** |

### Cache, Session & Redis

| Name | Required | Default | Purpose | Notes |
|---|---|---|---|---|
| `CACHE_STORE` | No | `redis` | Cache repository driver (`redis`, `file`, `array`) | Source: [`config/cache.php`](../config/cache.php) |
| `SESSION_DRIVER` | No | `redis` | HTTP session persistence driver (`redis`, `file`, `cookie`) | Source: [`config/session.php`](../config/session.php) |
| `REDIS_HOST` | Conditional | `127.0.0.1` | Redis server address | Required if using Redis cache/queue |
| `REDIS_PORT` | Conditional | `6379` | Redis connection port | Standard port `6379` |
| `REDIS_PASSWORD` | Conditional | `null` | Redis authentication password | **Secret** |

### Queue & Background Jobs

| Name | Required | Default | Purpose | Notes |
|---|---|---|---|---|
| `QUEUE_CONNECTION` | Yes | `redis` | Asynchronous queue driver (`redis`, `database`, `sync`) | Use `redis` in production |
| `REDIS_QUEUE` | No | `default` | Primary Redis queue name | Source: [`config/queue.php`](../config/queue.php) |

### Ingestion & Metrics Engine

| Name | Required | Default | Purpose | Notes |
|---|---|---|---|---|
| `INGEST_LEASE_TIMEOUT_SECONDS` | No | `600` | Worker lease TTL before recovery re-queues a batch | Default: 10 minutes |
| `INGEST_MAX_BATCH_SIZE_MB` | No | `5` | Maximum uncompressed batch payload size | Enforced by [`IngestBatchController`](../app/Http/Controllers/Api/V1/IngestBatchController.php) |
| `METRICS_CACHE_TTL` | No | `86400` | Cache lifetime in seconds for historical closed days | Source: [`config/metrics.php`](../config/metrics.php) |
| `METRICS_CACHE_LIVE_TTL` | No | `300` | Cache lifetime in seconds for today's live aggregates | 5-minute rolling freshness |

### Artificial Intelligence & LLM Services

| Name | Required | Default | Purpose | Notes |
|---|---|---|---|---|
| `AI_DEFAULT_PROVIDER` | No | `openai` | Default LLM provider driver (`openai`, `azure`, `anthropic`) | Tenant can override in UI |
| `OPENAI_API_KEY` | Conditional | `null` | Global fallback OpenAI API secret key | **Secret** |
| `ANTHROPIC_URL` | No | `https://api.mwapi.dev/v1` | Base API gateway URL for Anthropic proxy driver | Configured in [`config/ai.php`](../config/ai.php) |
| `AI_DEFAULT_MODEL` | No | `gpt-4o-mini` | Default model identifier for assistant queries | Fast, cost-efficient default |
| `AI_MONTHLY_TOKEN_BUDGET` | No | `1000000` | Default monthly token limit for new organizations | Enforced by [`EnforceBudget`](../app/Ai/Middleware/EnforceBudget.php) |

---

## Menu Intelligence Settings (`config/menu.php`)

Analytical parameters governing the nightly menu intelligence precomputation:

| Parameter Key | Default | Description |
|---|---|---|
| `menu.intelligence.months_back` | `3` | Lookback window in months evaluated for stats & affinities |
| `menu.intelligence.trusted_confidence` | `0.70` | Threshold above which algorithmic classifications are considered trusted |
| `menu.intelligence.min_days_on_sale` | `14` | Minimum active days an item must trade before being ranked |
| `menu.intelligence.min_baskets` | `200` | Minimum checks in a period required before affinity mining runs |
| `menu.intelligence.min_support_orders` | `5` | Minimum co-occurring checks to create an item affinity link |
| `menu.intelligence.min_support_ratio` | `0.002` | Minimum check frequency percentage for candidate itemsets |
| `menu.intelligence.max_itemset_size` | `4` | Maximum items grouped in an APRIORI association set |
| `menu.intelligence.combo_overlap_threshold` | `0.80` | Jaccard overlap similarity threshold flagging duplicate combo packages |
| `menu.intelligence.build_delay_minutes` | `15` | Debounce delay before background build dispatches after POS ingestion changes |

---

## Runtime / Admin Settings

These settings are stored in relational database tables and managed dynamically by administrators without code deployments:

| Setting Entity | Where Edited | Effect | Owning Feature |
|---|---|---|---|
| **AI Provider Credentials** | App Panel: `/app/management/ai-settings` | Configures per-tenant OpenAI/Azure API keys and model overrides | [AI Assistant](features/ai-assistant.md) |
| **Token Monthly Quota** | App Panel: `/app/management/ai-settings` | Sets the monthly token ceiling before LLM queries pause | [AI Assistant](features/ai-assistant.md) |
| **Item Role Overrides** | App Panel: `/app/management/item-roles` | Sets BCG menu quadrants and manual overrides | [Menu Engineering & Intelligence](features/menu-engineering-and-roles.md) |
| **Item Card Overrides** | App Panel: `/app/management/item-cards` | Overrides serving portions (`serves_n`), price tiers, and seasons | [Menu Engineering & Intelligence](features/menu-engineering-and-roles.md) |
| **Component Classes** | App Panel: `/app/management/component-classes` | Reviews and overrides raw material food classes (`protein`, `carb`, etc.) | [Menu Engineering & Intelligence](features/menu-engineering-and-roles.md) |
| **Combo Candidates** | App Panel: `/app/management/combo-candidates` | Approves or rejects algorithmically generated promotional combo meals | [Menu Engineering & Intelligence](features/menu-engineering-and-roles.md) |
| **Branch Scoring Profile** | App Panel: `/app/management/scoring-profile` | Adjusts weights across the 7 KPIs for branch performance ranking | [Targets & Ranking](features/targets-and-ranking.md) |
| **Calendar Events** | App Panel: `/app/calendar-events` | Flags holidays and store closures excluded from target baselines | [Targets & Ranking](features/targets-and-ranking.md) |
| **Expense Category Map** | App Panel: `/app/expense-category-mappings` | Maps raw POS expense account strings into standard accounting categories | [Analytics Reports](features/analytics-reports.md) |

---

## Secrets Handling

- **Never Commit Secrets:** Passwords, API tokens, and private encryption keys must never be committed to source control. The `.gitignore` file excludes `.env`.
- **Key Rotation:**
  - `APP_KEY`: Rotating `APP_KEY` invalidates existing user sessions and encrypted cookie values.
  - Sanctum Tokens: POS terminal tokens are rotated via `/api/v1/pair/rotate` with a 5-minute transition grace period.

---

## Per-Environment Differences

| Setting | Local Development | Staging | Production |
|---|---|---|---|
| `APP_ENV` | `local` | `staging` | `production` |
| `APP_DEBUG` | `true` | `false` | `false` |
| `DB_CONNECTION` | `sqlite` or `mysql` | `mysql` | `mysql` or `pgsql` |
| `QUEUE_CONNECTION` | `sync` or `redis` | `redis` | `redis` |
| `CACHE_STORE` | `file` or `redis` | `redis` | `redis` |
| `AI_DEFAULT_MODEL` | `gpt-4o-mini` | `gpt-4o-mini` | `gpt-4o-mini` (or `gpt-4o`) |
| `LOG_LEVEL` | `debug` | `info` | `warning` |
