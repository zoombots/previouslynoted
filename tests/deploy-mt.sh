#!/bin/sh
# Copy the app to the local multi-tenant test site (http://127.0.0.1:8082), keeping its data folder.
set -e
SRC="$(cd "$(dirname "$0")/../app" && pwd)"
DST=/var/www/pnmt
mkdir -p "$DST/data"
rsync -a --delete --exclude data/ "$SRC/" "$DST/"
cp "$SRC/data/.htaccess" "$DST/data/.htaccess" 2>/dev/null || true
chown -R www-data:www-data "$DST"
