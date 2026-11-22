#!/usr/bin/env bash
set -euo pipefail
umask 0007

cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
php_bin=${PHP_BIN:-php8.3}

if [[ ! -r .env ]]; then
    printf '%s\n' 'Configure the production .env before deploying.' >&2
    exit 1
fi

"$php_bin" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || {
    printf '%s\n' 'PHP 8.3 or newer is required.' >&2
    exit 1
}
node -e 'const [major, minor] = process.versions.node.split(".").map(Number); process.exit(major > 22 || (major === 22 && minor >= 12) ? 0 : 1);' || {
    printf '%s\n' 'Node.js 22.12 or newer is required.' >&2
    exit 1
}

if ! grep -q '^APP_ENV=production$' .env || ! grep -Eq '^APP_KEY=base64:.+$' .env; then
    printf '%s\n' 'Set APP_ENV=production and generate the production APP_KEY before deploying.' >&2
    exit 1
fi

"$php_bin" "$(command -v composer)" install --no-dev --no-interaction --prefer-dist --optimize-autoloader
"$php_bin" "$(command -v composer)" check-platform-reqs --no-dev
npm ci --include=dev
npm run build
"$php_bin" artisan config:clear
"$php_bin" artisan migrate --seed --force
"$php_bin" artisan config:cache
"$php_bin" artisan route:cache
"$php_bin" artisan view:cache
"$php_bin" artisan queue:restart
"$php_bin" artisan up
