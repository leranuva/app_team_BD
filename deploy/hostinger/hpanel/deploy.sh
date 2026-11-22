#!/usr/bin/env bash
set -euo pipefail
umask 0022

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../.."
app_dir=$PWD
webroot=$app_dir/../public_html
php_bin=${PHP_BIN:-/opt/alt/php83/usr/bin/php}
composer_bin=${COMPOSER_BIN:-/usr/local/bin/composer2}

if [[ $(basename "$app_dir") != blue-draft-team || ! -d "$webroot" ]]; then
    printf '%s\n' 'Place the checkout at ~/domains/bluedraft.site/blue-draft-team, next to public_html.' >&2
    exit 1
fi

if [[ ! -r .env ]] || ! grep -q '^APP_ENV=production$' .env \
    || ! grep -q '^APP_URL=https://bluedraft.site$' .env \
    || ! grep -q '^DB_CONNECTION=mariadb$' .env \
    || ! grep -Eq '^APP_KEY=base64:.+$' .env; then
    printf '%s\n' 'Configure the production .env, MariaDB and APP_KEY before deploying.' >&2
    exit 1
fi

if [[ ! -s public/build/manifest.json ]]; then
    printf '%s\n' 'Build the current revision with Vite and copy public/build into this checkout.' >&2
    exit 1
fi

"$php_bin" -r 'exit(PHP_VERSION_ID >= 80300 && extension_loaded("pdo_mysql") ? 0 : 1);' || {
    printf '%s\n' 'PHP 8.3 or newer with pdo_mysql is required.' >&2
    exit 1
}

"$php_bin" "$composer_bin" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
"$php_bin" "$composer_bin" check-platform-reqs --no-dev
"$php_bin" artisan config:clear
"$php_bin" artisan migrate --seed --force
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache

# Publish public assets only. The web entry point loads Laravel from the private checkout.
tar -C public --exclude='./index.php' -cf - . | tar -C "$webroot" -xf -
install -m 644 deploy/hostinger/hpanel/index.php "$webroot/index.php"
"$php_bin" artisan queue:restart
"$php_bin" artisan up
