# Gama AI SaaS

Laravel backend for Gama AI. Phase 2: accounts, sites, plugin tokens and the
usage ledger. No AI calls yet.

## What is here

| Part | Where |
| --- | --- |
| Accounts with a monthly token budget | `app/Models/Account.php` |
| Sites and plugin tokens (only a SHA-256 hash is stored) | `app/Models/Site.php` |
| Append-only usage ledger | `app/Models/UsageEntry.php`, `database/migrations/*usage_entries*` |
| Reserve, settle, release, adjust and the monthly summary | `app/Services/Usage/UsageMeter.php` |
| Plugin API (`Authorization: Bearer gsk_...`) | `routes/api.php`, `app/Http/Controllers/Api/SiteController.php` |
| Model prices for estimated cost | `config/gama.php` |

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

Production uses PostgreSQL (`DB_CONNECTION=pgsql`). CI runs the tests on
SQLite and PostgreSQL.
