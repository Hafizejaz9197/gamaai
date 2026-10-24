#!/usr/bin/env bash
# Builds build/gama-saas-<git sha>.zip: the app with production dependencies
# installed, for hosting without Composer (such as cPanel File Manager).
# No .env is included; create it on the server.
set -euo pipefail
cd "$(dirname "$0")/.."

sha=$(git rev-parse --short HEAD)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/gama-saas" build
tar --exclude=./vendor --exclude=./node_modules --exclude=./tests --exclude=./build \
    --exclude=./.env --exclude='./storage/logs/*' --exclude='./storage/framework/*/*' \
    --exclude=./database/database.sqlite --exclude=./.phpunit.cache -cf - . | tar -xf - -C "$work/gama-saas"
(cd "$work/gama-saas" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
rm -f "build/gama-saas-$sha.zip"
(cd "$work" && zip -qr - gama-saas -x '*/.git/*') > "build/gama-saas-$sha.zip"
echo "build/gama-saas-$sha.zip"
