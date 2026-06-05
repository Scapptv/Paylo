#!/usr/bin/env bash
#
# Paylo — production deploy script (bare VPS + Nginx)
#
# Usage: bash deploy/deploy.sh
# Run from project root (məs /var/www/paylo).
#
# Bu script idempotent-dir — hər dəfə icra etmək təhlükəsizdir. Hər addım uğursuz
# olarsa, exit non-zero edir (set -e) və CI/cron alert tetikləyir.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

# Mövcud .env-i təsdiqlə — yoxdursa stop.
if [[ ! -f .env ]]; then
    echo "❌ .env yoxdur. .env.production.example-i kopyalayıb doldur." >&2
    exit 1
fi

# Maintenance mode — aktiv user trafikini blokla (`secret` ilə deploy edən
# adminin keçidi). Health-check endpoint /up Maintenance-da da işləyir.
echo "🔧 Maintenance mode → ON"
php artisan down --secret="$(openssl rand -hex 16)" --render="errors::503" || true

# 1) Yeni kodu çək (CI/CD push etmişsə bu addım skip).
if [[ -d .git ]]; then
    echo "📦 Git pull..."
    git pull --ff-only origin main
fi

# 2) Composer — production rejimində (dev paketlər istisna).
echo "📦 Composer install..."
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 3) Frontend asset build.
if [[ -f package.json ]]; then
    echo "🎨 NPM build..."
    npm ci --no-audit --no-fund
    npm run build
fi

# 4) Migration — fail-fast əgər tətbiq edilməyən migration varsa.
echo "🗄  Database migrate..."
php artisan migrate --force --no-interaction

# 5) Cache təmizlə + yenidən qur.
echo "🧹 Cache təmizlə..."
php artisan optimize:clear

echo "⚡ Cache yenidən qur..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache 2>/dev/null || true   # Laravel 11-də opsional

# 6) Storage symlink (public/storage → storage/app/public).
if [[ ! -L public/storage ]]; then
    echo "🔗 Storage symlink..."
    php artisan storage:link
fi

# 7) Queue worker-ı yenilə (supervisord əgər qurulubsa).
echo "♻  Queue restart..."
php artisan queue:restart

# 8) Schedule reqister yoxla (cron-da `* * * * * php artisan schedule:run` olmalıdır).
echo "📅 Scheduled commands:"
php artisan schedule:list || true

# 9) Maintenance mode OFF.
echo "✅ Maintenance mode → OFF"
php artisan up

echo "🎉 Deploy tamam: $(date -Iseconds)"
