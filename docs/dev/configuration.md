# Configuration

Configuration values are loaded from `.env` and `config/*.php`. Every key below is verified against `.env.example` and `config/`.

---

## 1. Core Application, Database & Queues

| Env Var | `.env.example` | `config/` Fallback | Source & Notes |
|---|---|---|---|
| `APP_NAME` | `Laravel` | `'Laravel'` | `config/app.php` |
| `APP_ENV` | `local` | `'production'` | `config/app.php` |
| `APP_KEY` | *(empty)* | `null` | Required AES key (`branches.branch_secret` encrypted cast) |
| `APP_DEBUG` | `true` | `false` | Must be `false` in production |
| `APP_URL` | `http://localhost:8000` | `'http://localhost'` | Base URL |
| `APP_LOCALE` | `ar` | `'en'` | Primary UI locale (`ar`, RTL) |
| `APP_FALLBACK_LOCALE` | `en` | `'en'` | Fallback locale |
| `APP_TIMEZONE` | `Africa/Cairo` | `'Africa/Cairo'` | All business-date & reporting logic uses `Africa/Cairo` |
| `DB_CONNECTION` | `mysql` | `'mysql'` | MySQL / MariaDB (`config/database.php`) |
| `SESSION_DRIVER` / `CACHE_STORE` | `database` | `'database'` | `config/session.php`, `config/cache.php` |
| `FILESYSTEM_DISK` | `local` | `'local'` | Local disk root is `storage_path('app/private')` (`config/filesystems.php`) |
| `QUEUE_CONNECTION` | `database` | `'database'` | `config/queue.php` (`db_queue_retry_after = 90` default) |
| `ASSISTANT_QUEUE_RETRY_AFTER` | `960` | `960` | Dedicated `assistant` queue connection `retry_after` (must exceed 900s job timeout) |
| `TRUSTED_PROXIES` | *(empty)* | `null` | Comma-separated proxy IPs or `*` (applied in `AppServiceProvider`) |

`ProcessIngestBatch::getLeaseDuration()` also reads `config('ingest.lease_duration_seconds')`, falling back to `30` seconds (no `.env` variable is mapped by default).

---

## 2. Metrics, Assistant & AI Billing

| Env Var | Default | Source & Notes |
|---|---|---|
| `METRICS_CACHE_TTL` | `60` | `config/metrics.php` — TTL (seconds) for historical `MetricsQuery` results (`0` disables) |
| `METRICS_CACHE_LIVE_TTL` | `15` | `config/metrics.php` — TTL (seconds) for queries touching today / hourly totals |
| `ASSISTANT_REACT_UI` | `true` | `config/assistant.php` — `true` redirects `/app/assistant` to `/assistant`; `false` serves Filament Livewire fallback |
| `AI_ROLES_TRUSTED_CONFIDENCE` | `0.70` | `config/ai.php` (`roles.trusted_confidence`) |
| `AI_ROLES_RECLASSIFY_AFTER_DAYS` | `7` | `config/ai.php` (`roles.reclassify_after_days`) |
| `AI_USD_TO_EGP` | `50.0` | `config/ai_billing.php` |
| `AI_DEFAULT_BUDGET_BASIC_EGP` | `0.0` | `config/ai_billing.php` |
| `AI_DEFAULT_BUDGET_PRO_EGP` | `1500.0` | `config/ai_billing.php` |
| `AI_RATE_LIMIT_USER_MESSAGES_PER_MIN` | `20` | `config/ai_billing.php` |
| `AI_RATE_LIMIT_ORG_CONCURRENT_RUNS` | `5` | `config/ai_billing.php` |
| `AI_PER_RUN_INPUT_TOKEN_CAP` | `120000` | `config/ai_billing.php` |
| `AI_PER_QUESTION_INPUT_TOKEN_CAP` | `240000` | `config/ai_billing.php` |

---

## 3. AI Providers & MCP (`config/ai.php`, `config/mcp.php`)

- **OpenAI:** `OPENAI_DRIVER` (`openai` default or `openai-compatible`), `OPENAI_URL` (`https://api.openai.com/v1`), `OPENAI_API_KEY`, `OPENAI_STORE` (`true`), `OPENAI_STREAM` (`true`), `OPENAI_MODEL` (`gpt-6.1-sol`).
- **Anthropic:** `ANTHROPIC_API_KEY`, `ANTHROPIC_URL` (`https://api.anthropic.com/v1`), `ANTHROPIC_BEARER_AUTH` (`false`, set `true` for gateways such as Cloudflare AI).
- **Cloudflare Workers AI (`cloudflare`):** `CLOUDFLARE_AI_URL`, `CLOUDFLARE_AI_TOKEN`, `CLOUDFLARE_AI_MODEL` (`@cf/google/gemma-4-26b-a4b-it`).
- **Other configured drivers:** `azure`, `bedrock`, `cohere`, `deepseek`, `eleven`, `gemini`, `groq`, `jina`, `mistral`, `ollama`, `openai-compatible`, `openrouter`, `typesafe`, `voyageai`, `xai`.
- **MCP:** `MCP_USER_ID` (required for `php artisan mcp:start restaurants` stdio server), `MCP_REDIRECT_DOMAINS` (comma-separated OAuth redirect origin prefixes; never `*`).

---

## 4. Menu Intelligence (`config/menu.php`)

| Key | Default | Meaning |
|---|---|---|
| `intelligence.months_back` | `3` | Rolling historical months analyzed |
| `intelligence.trusted_confidence` | `0.70` | Auto-classification confidence threshold |
| `intelligence.rejudge_days` | `30` | Days before low-confidence items are re-judged |
| `intelligence.classification_batch` | `60` | Max items per LLM batch call |
| `intelligence.min_days_on_sale` | `14` | Minimum open-sale days before ranking |
| `intelligence.min_baskets` / `min_support_orders` / `min_support_ratio` | `200` / `5` / `0.002` | Affinity mining thresholds |
| `intelligence.max_itemset_size` | `4` | Max items in mined affinity sets |
| `intelligence.combo_overlap_threshold` | `0.80` | Jaccard overlap threshold for existing combos |
| `intelligence.build_delay_minutes` | `15` | Post-ingest debounce delay before `BuildMenuIntelligence` |
| `seasons.winter_months` / `summer_months` | `[11,12,1,2]` / `[6,7,8]` | Seasonal matching rules (plus `ramadan` date ranges) |

---

## 5. Runtime Database Settings (`/app`)

Configured per organization in the Filament App Panel:
- **AI Settings** (`/app/management/ai-settings`): provider, model, failover chain, reasoning level, optional encrypted BYO key, monthly/per-user budgets.
- **Scoring Profile** (`/app/management/scoring-profile`): 7-KPI branch ranking weights.
- **Calendar Events** (`/app/calendar-events`): Ramadan/Eid/holiday/promo dates excluded from target baselines.
- **Expense Category Mappings** (`/app/expense-category-mappings`): `category_override` and `avg_month_rate` on `dim_expense_types`.
- **Menu Intelligence Review Pages**: `/app/management/item-roles`, `/app/management/item-cards`, `/app/management/component-classes`, `/app/management/combo-candidates`.
