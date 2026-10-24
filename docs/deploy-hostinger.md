# Deploying the SaaS on Hostinger (hPanel)

The test site's hosting is Hostinger, managed in hPanel, and SSH is
enabled. All commands below run in an SSH session:

```sh
ssh -p 65002 <ssh username>@<ssh ip>
```

The username, IP and port are on hPanel → Advanced → SSH Access. Type the
password yourself; never send it in chat.

## 1. Subdomain and SSL

hPanel → **Domains → Subdomains** → create `gama-api` under
`avadawebsites.com`, and keep the folder it proposes.

hPanel → **Security → SSL**: check that `gama-api.avadawebsites.com` has a
certificate. Install one if it shows none. It must open over `https://`.

## 2. PHP version

hPanel → **Advanced → PHP Configuration** → PHP **8.3 or newer**.

Then check over SSH:

```sh
php -v
```

If it shows a version below 8.3, use the newer binary in every command
below, for example `/opt/alt/php83/usr/bin/php` instead of `php`.

## 3. Database

hPanel → **Databases → Management** → create a database and user with a
strong password. Hostinger adds a prefix, for example `u123_gama`. Write
the full names down for `.env`.

## 4. Upload and unpack

Upload `gama-saas-<version>.zip` with hPanel → **Files → File Manager**.
File Manager opens in `~/domains/avadawebsites.com` (it shows
`public_html` and `DO_NOT_UPLOAD_HERE`), not in your SSH home folder.
Upload the zip there, next to `public_html`, not inside it. Then over SSH:

```sh
mv ~/domains/avadawebsites.com/gama-saas-*.zip ~/
cd ~
unzip -q gama-saas-*.zip        # creates ~/gama-saas
```

If File Manager already unpacked it, move the folder instead:
`mv ~/domains/avadawebsites.com/gama-saas ~/gama-saas`.

## 5. Make only `public` reachable from the web

The subdomain folder gets replaced by a link to the app's `public` folder,
so the rest of the app (including `.env`) stays outside the web root. First
find the folder hPanel created:

```sh
ls -d ~/domains/avadawebsites.com/public_html/gama-api
```

If that path exists, move it aside and link it:

```sh
mv ~/domains/avadawebsites.com/public_html/gama-api ~/gama-api-default-folder
ln -s ~/gama-saas/public ~/domains/avadawebsites.com/public_html/gama-api
```

If the path is different, send a screenshot of the `ls` output before going
on. Don't remove or move anything else in `public_html`: the WordPress
site lives there.

## 6. Settings file (`.env`)

```sh
cd ~/gama-saas
nano .env
```

Paste this, replace the values in `< >`, then press Ctrl+O, Enter, Ctrl+X.
Type the database password and the Claude API key yourself.

```
APP_NAME="Gama AI"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://gama-api.avadawebsites.com

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=<full database name>
DB_USERNAME=<full database user>
DB_PASSWORD=<database password>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync

ANTHROPIC_API_KEY=<your Claude API key from console.anthropic.com>
GAMA_AI_MODEL=claude-opus-5-5
GAMA_AI_EFFORT=medium
```

```sh
chmod 600 .env
```

## 7. Tables, account and site token

```sh
php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan gama:account-create "Sabahat" --budget=2000000
php artisan gama:site-create 1 "Sabahat test site" https://avadawebsites.com/sabahat
```

The last command prints a `gsk_...` token **once**. Copy it straight into
step 8. Don't send it in chat.

Check that the service answers:

```sh
curl -s https://gama-api.avadawebsites.com/up -o /dev/null -w "%{http_code}\n"   # 200
```

## 8. Connect the plugin

Upload plugin 0.4.0 in WordPress. Then go to **Settings → Gama AI**:

- Service URL: `https://gama-api.avadawebsites.com`
- Site token: the `gsk_...` token

Click **Save and test connection**. It should say **Connected**.

## 9. Admin dashboard

Create your admin login over SSH. It asks for the password twice and
doesn't show it while you type:

```sh
cd ~/gama-saas
php artisan gama:admin-create you@example.com
```

Then open `https://gama-api.avadawebsites.com/admin` and log in. The
dashboard shows each month's tokens and cost by account, site, model and
request type, the full usage ledger, and lets you create accounts, change
budgets, add sites (the token is shown once) and give a site a new token.
Run the same command again to change the password.

## Updating later

Upload the new zip to the home folder, then run the commands below. The zip
has no `.env`, so the existing one is kept.

```sh
cd ~ && unzip -oq gama-saas-<new>.zip
cd ~/gama-saas && php artisan migrate --force && php artisan config:cache && php artisan route:cache
```
