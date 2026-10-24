# Gama AI SaaS

Laravel backend for Gama AI: accounts, sites, plugin tokens, the usage
ledger, and AI edit requests answered by Claude.

## What is here

| Part | Where |
| --- | --- |
| Accounts with a monthly token budget | `app/Models/Account.php` |
| Sites and plugin tokens (only a SHA-256 hash is stored) | `app/Models/Site.php` |
| Append-only usage ledger | `app/Models/UsageEntry.php`, `database/migrations/*usage_entries*` |
| Reserve, settle, release, adjust and the monthly summary | `app/Services/Usage/UsageMeter.php` |
| Plugin API (`Authorization: Bearer gsk_...`) | `routes/api.php`, `app/Http/Controllers/Api/SiteController.php` |
| Claude edit planner (page model + request → edit operations) | `app/Services/Ai/AnthropicEditPlanner.php` |
| Model, effort and prices | `config/gama.php` |

### How usage is metered

Every AI call is bracketed in the ledger:

1. `reserve` holds the most the call may use. It fails when the month's budget
   (used + held) cannot cover it. It runs under a lock on the account row, so
   two requests cannot both spend the last of a budget.
2. `settle` records the provider's real input and output tokens, our
   estimated cost and, when the provider reports one, its own cost.
3. `release` drops a reservation whose call never happened.

Rows are never updated or deleted. Corrections are `adjust` rows.

### Plugin API

| Route | What it does |
| --- | --- |
| `POST /api/v1/site/check-in` | The plugin reports `plugin_version`, `avada_builder_version` and `avada_verified`, and gets this month's usage back. |
| `GET /api/v1/site/usage` | This month's usage: budget, used, held, available, estimated cost. |
| `POST /api/v1/site/edit-requests` | `page_id`, `instruction`, `sections` (page model) and `elements` (element settings) in; `summary`, `operations` and `usage` out. 402 when the budget cannot cover the call, 502 when the AI call fails. 10 requests per minute per site. |

### AI edits

The planner sends Claude (`GAMA_AI_MODEL`, default `claude-opus-5-5`, effort
`GAMA_AI_EFFORT`, default `medium`) a fixed system prompt, then the page model
and element settings as one cached block, then the request. The answer is
constrained to a JSON schema of the five edit operations. Server-side refusal
fallback is on (`fallbacks: "default"`), so the ledger records the model that
actually answered. The plugin checks every operation against Avada again
before writing the draft.

Set `ANTHROPIC_API_KEY` in the server environment. Never commit it.

## Local setup

```sh
cd saas
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan gama:account-create "Sabahat" --budget=2000000
php artisan gama:site-create 1 "Sabahat" https://avadawebsites.com/sabahat   # prints the plugin token once
php artisan test
```

Production can use MariaDB/MySQL or PostgreSQL. CI runs the tests on SQLite,
PostgreSQL and MariaDB. For cPanel hosting, `bin/build-deploy-zip.sh` builds a
zip with production dependencies included; see `docs/deploy-hostinger.md` (the test site's host) or `docs/deploy-cpanel.md`.
