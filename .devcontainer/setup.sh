#!/usr/bin/env bash
# Paylo — GitHub Codespaces setup. Backend-i SQLite ilə işə hazırlayır
# (MySQL/Redis lazım deyil). Codespace yaradılanda bir dəfə işləyir.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> 1/5 Composer install"
composer install --no-interaction --prefer-dist

echo "==> 2/5 .env + app key"
[ -f .env ] || cp .env.example .env
php artisan key:generate --force

echo "==> 3/5 SQLite konfiqi (Codespaces — xarici servis lazım deyil)"
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env || true
sed -i "s#^DB_DATABASE=.*#DB_DATABASE=$(pwd)/database/database.sqlite#" .env || true
sed -i 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' .env || true
sed -i 's/^CACHE_STORE=.*/CACHE_STORE=file/' .env || true
sed -i 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' .env || true
mkdir -p database
rm -f database/database.sqlite
touch database/database.sqlite

echo "==> 4/5 Migrate + seed (təmiz DB)"
php artisan migrate:fresh --seed --force

echo "==> 5/5 Frontend build (Vite/Inertia)"
npm install
npm run build

cat <<'DONE'

============================================================
✅ Paylo backend Codespaces-də hazırdır!

İşə salmaq (terminalda):
    php artisan serve --host=0.0.0.0 --port=8000

→ Port 8000 avtomatik forward olunur (PORTS tab → aç / Open in Browser).
→ Login:  admin@paylo.az / password   (digər seed user-lər də "password")

Frontend hot-reload istəyirsənsə (opsional, ayrı terminal):
    npm run dev
============================================================
DONE
