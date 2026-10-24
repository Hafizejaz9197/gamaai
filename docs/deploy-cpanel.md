# Deploying the SaaS on cPanel

The Gama AI service (`saas/`) runs on the same cPanel hosting as the test
site. That hosting has MariaDB 11.8, and WordPress there runs on PHP 8.5.
CI tests the service on MariaDB, PostgreSQL and SQLite.

You need cPanel access and **Terminal** (or SSH) in cPanel for steps 6 and 7.
If Terminal is missing, ask the host to enable it. Without it the database
tables cannot be created.

## 1. Subdomain

cPanel → **Domains** → **Create A New Domain**:

- Domain: for example `gama-api.avadawebsites.com`
- Document root: `gama-saas/public`, outside `public_html`, so that only
  the `public` folder is reachable from the web.

Then cPanel → **SSL/TLS Status** → run **AutoSSL** for the new subdomain.
It must open over `https://`.

## 2. PHP version

cPanel → **MultiPHP Manager** → set the subdomain to PHP **8.3 or newer**.
In **Select PHP Version** → Extensions (if your host has it), make sure these
are on: `pdo_mysql`, `mbstring`, `openssl`, `intl`, `curl`, `fileinfo`,
`tokenizer`, `xml`, `ctype`, `bcmath`.

## 3. Database

cPanel → **MySQL® Databases**:

1. Create a database, for example `youruser_gama`.
2. Create a user with a strong password.
3. Add the user to the database with **ALL PRIVILEGES**.

## 4. Upload the code

Upload `gama-saas-<version>.zip` to your home folder with **File Manager**,
then **Extract** it there. That creates `~/gama-saas`. The zip already holds
every library, so Composer is not needed on the server.

## 5. Settings file (`.env`)

In File Manager, open `gama-saas`, create a file named `.env`, and paste
this. Replace the values in `< >`. Type the database password and the
Claude API key here yourself. Never send them in chat.

```
APP_NAME="Gama AI"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://<your subdomain>

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<database name>
DB_USERNAME=<database user>
DB_PASSWORD=<database password>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=sync

ANTHROPIC_API_KEY=<your Claude API key from console.anthropic.com>
GAMA_AI_MODEL=claude-opus-5-5
GAMA_AI_EFFORT=medium
```

Right-click `.env` → **Change Permissions** → `600`.

## 6. Create the tables

cPanel → **Terminal**:

```sh
cd ~/gama-saas
php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

If `php -v` shows a version below 8.3, use the full path of a newer PHP
binary, for example `/opt/cpanel/ea-php83/root/usr/bin/php artisan ...`.

## 7. Create the account and the site token

Still in Terminal:

```sh
php artisan gama:account-create "Sabahat" --budget=2000000
php artisan gama:site-create 1 "Sabahat test site" https://avadawebsites.com/sabahat
```

The second command prints a token starting with `gsk_` **once**. Copy it
straight into the next step. Don't send it in chat.

`--budget` is the monthly token limit for the account. Leave it out for no
limit.

## 8. Connect the plugin

WordPress admin → **Settings → Gama AI**:

- Service URL: `https://<your subdomain>`
- Site token: the `gsk_...` token

Click **Save and test connection**. It should say **Connected** and show the
tokens left this month.

## Updating later

Upload and extract the new zip over `~/gama-saas`. Keep `.env`, because the
zip has none. Then run:

```sh
cd ~/gama-saas
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```
