# Paylo — Production Deployment

Bu sənəd Paylo loyalty platformasını **bare VPS + Nginx + PHP-FPM + MySQL + Redis** üzərində production-ə qoymaq üçün addım-addım təlimat verir. Hər addım deploy.sh və ya konkret artefaktla bağlıdır.

## Tərkib

1. [Tələblər](#1-tələblər)
2. [Server ilkin quraşdırma](#2-server-ilkin-quraşdırma)
3. [Kod deploy](#3-kod-deploy)
4. [.env konfiqurasiyası](#4-env-konfiqurasiyası)
5. [Database](#5-database)
6. [Redis](#6-redis)
7. [Nginx + SSL](#7-nginx--ssl)
8. [Queue worker (Supervisor)](#8-queue-worker-supervisor)
9. [Cron — Laravel scheduler](#9-cron--laravel-scheduler)
10. [Sentry — error tracking](#10-sentry--error-tracking)
11. [spatie/laravel-backup — backup](#11-spatielaravel-backup--backup)
12. [Test edilməsi](#12-test-edilməsi)
13. [Rollback](#13-rollback)

---

## 1. Tələblər

- **OS:** Ubuntu 22.04 LTS və ya 24.04 LTS (digər Linux dist-lərində uyğun ekvivalent komandalar).
- **PHP 8.2+** — `php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-mbstring php8.2-xml php8.2-zip php8.2-curl php8.2-gd php8.2-intl php8.2-bcmath`.
- **MySQL 8+** (InnoDB; ledger immutability InnoDB row-locking tələb edir).
- **Redis 6+**.
- **Nginx 1.20+**.
- **Composer 2+**.
- **Node 18+** və **npm 9+** (frontend build üçün).
- **Supervisor** (queue worker idarəetməsi).
- **certbot** (Let's Encrypt SSL).

---

## 2. Server ilkin quraşdırma

```bash
# Sistem yenilə
sudo apt update && sudo apt upgrade -y

# Lazımi paketlər
sudo apt install -y nginx mysql-server redis-server supervisor certbot python3-certbot-nginx \
    php8.2 php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-mbstring \
    php8.2-xml php8.2-zip php8.2-curl php8.2-gd php8.2-intl php8.2-bcmath \
    composer nodejs npm

# Tətbiq istifadəçisi (root-suz deploy)
sudo adduser --system --group --home /var/www/paylo paylo

# Project root (www-data Nginx istifadəçisidir — Ubuntu defaultu)
sudo mkdir -p /var/www/paylo
sudo chown -R www-data:www-data /var/www/paylo
```

---

## 3. Kod deploy

```bash
sudo -u www-data git clone https://github.com/yourorg/paylo.git /var/www/paylo
cd /var/www/paylo
```

Sonrakı bütün deploy-lar [`deploy/deploy.sh`](deploy/deploy.sh) skripti vasitəsi ilə olmalıdır:

```bash
sudo -u www-data bash deploy/deploy.sh
```

Skript: maintenance mode → git pull → composer install → npm build → migrate → `config:cache`/`route:cache`/`view:cache` → `queue:restart` → maintenance off.

---

## 4. .env konfiqurasiyası

```bash
sudo -u www-data cp .env.production.example .env
sudo -u www-data php artisan key:generate
sudo -u www-data nano .env   # bütün CHANGE ME dəyərlərini doldur
```

**Tələb olunan dəyərlər:**

| Açar | Nümunə | Qeyd |
|---|---|---|
| `APP_URL` | `https://app.paylo.az` | **HTTPS məcburi**. `AppServiceProvider` URL::forceScheme bunun əsasında işləyir. |
| `APP_DEBUG` | `false` | Production-da heç vaxt `true` olmasın. |
| `TRUSTED_PROXIES` | `127.0.0.1` | Cloudflare arxasındasansa Cloudflare IP range. |
| `DB_PASSWORD` | uzun random | `openssl rand -base64 32`. |
| `REDIS_PASSWORD` | uzun random | Redis ACL-də eyni təyin et. |
| `SESSION_DOMAIN` | `.paylo.az` | Subdomain cookie share üçün. |
| `SANCTUM_STATEFUL_DOMAINS` | `app.paylo.az,admin.paylo.az` | SPA cookie auth. |
| `CORS_ALLOWED_ORIGINS` | konkret URL siyahısı | Mobile app deyilsə, yalnız web domain-lər. |
| `SENTRY_LARAVEL_DSN` | `https://xxx@oXX.ingest.sentry.io/XX` | Sentry layihə yaradıb DSN götürün. |
| `BACKUP_ARCHIVE_PASSWORD` | uzun random | Backup zip-ləri AES-256 ilə şifrələyir. |
| `MAIL_*` | SMTP gateway | Backup notification və error alert üçün. |

---

## 5. Database

```bash
# MySQL-də user və DB yarat
sudo mysql <<SQL
CREATE DATABASE paylo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'paylo_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON paylo.* TO 'paylo_app'@'localhost';
FLUSH PRIVILEGES;
SQL

# Migration
cd /var/www/paylo
sudo -u www-data php artisan migrate --force

# (Opsional) seed test data — production-da etmə.
# sudo -u www-data php artisan db:seed --force
```

InnoDB defaultdur — manual yoxlamağa ehtiyac yoxdur. `ledger_entries` cədvəlində triggers (immutability) avtomatik yaranır.

---

## 6. Redis

```bash
# Redis ACL — `requirepass` /etc/redis/redis.conf
sudo nano /etc/redis/redis.conf
# Aşağıdakı sətri uncomment edib güclü parol qoy:
#   requirepass <REDIS_PASSWORD_FROM_ENV>
# Bind 127.0.0.1 only (server yalnız öz prosesindən qoşulsun):
#   bind 127.0.0.1 ::1
# Persistence (AOF tövsiyə olunur):
#   appendonly yes
sudo systemctl restart redis

# Test
redis-cli -a "$REDIS_PASSWORD" ping
# > PONG
```

Tətbiq cache, session və queue üçün eyni Redis-i istifadə edir — `database.redis.default` connection. Cache üçün ayrı DB (`database 1`) avtomatik konfiq olunur.

---

## 7. Nginx + SSL

```bash
sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/paylo
sudo nano /etc/nginx/sites-available/paylo   # server_name, root, SSL yollarını dəqiqləşdir
sudo ln -s /etc/nginx/sites-available/paylo /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default

# SSL — Let's Encrypt
sudo certbot --nginx -d app.paylo.az -d admin.paylo.az --redirect --agree-tos -m admin@paylo.az -n

sudo nginx -t && sudo systemctl reload nginx
```

certbot avtomatik renewal cron-u qurur (`/etc/cron.d/certbot`).

---

## 8. Queue worker (Supervisor)

```bash
sudo cp deploy/supervisor.conf.example /etc/supervisor/conf.d/paylo-worker.conf
sudo nano /etc/supervisor/conf.d/paylo-worker.conf   # yolları dəqiqləşdir (user, path)

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start paylo-worker:*
sudo supervisorctl status paylo-worker:*
# > paylo-worker:paylo-worker_00     RUNNING   pid 12345, uptime 0:00:05
# > paylo-worker:paylo-worker_01     RUNNING   pid 12346, uptime 0:00:05
```

Hər deploy-da `php artisan queue:restart` çağırılır (deploy.sh içində) — supervisor avtomatik worker-ləri yenidən başladır.

---

## 9. Cron — Laravel scheduler

```bash
sudo -u www-data crontab -e
# Yapışdır (deploy/crontab.example-dən):
# * * * * * cd /var/www/paylo && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler bütün gündəlik işləri (`routes/console.php`-də qeydiyyatdan keçən):
- 01:30 — `backup:clean`
- 01:45 — `backup:run`
- 02:00 — `loyalty:settlement-reconcile --for=yesterday`
- 03:00 — `loyalty:expire-buckets`
- 05:00 — `backup:monitor`

`php artisan schedule:list` ilə hamısını yoxlamaq olar.

---

## 10. Sentry — error tracking

1. https://sentry.io/ → yeni Laravel layihə yarat.
2. DSN-i `.env`-də `SENTRY_LARAVEL_DSN=...` təyin et.
3. Deploy script `config:cache` çağırır — Sentry konfiq cache-ə girir.
4. Test:
   ```bash
   sudo -u www-data php artisan sentry:test
   ```
   Sentry dashboard-da test event görünməlidir.

`bootstrap/app.php` `reportable` handler-i bütün unhandled exception-ları Sentry-ə göndərir. PII default-da göndərilmir (`SENTRY_SEND_DEFAULT_PII=false` GDPR uyğun).

---

## 11. spatie/laravel-backup — backup

Backup avtomatik gündəlik 01:45-də işləyir (cron + scheduler). Manual icra:

```bash
sudo -u www-data php artisan backup:run
sudo -u www-data php artisan backup:list   # mövcud backup faylları
sudo -u www-data php artisan backup:monitor # sağlamlıq + bildiriş
```

**Lokal disk (`BACKUP_DISKS=local`):** `storage/app/Paylo/*.zip` (AES-256 şifrəli).

**Off-site (tövsiyə olunur):**
1. `config/filesystems.php`-da `s3` disk qur (AWS_* env dəyərləri).
2. `.env`-də `BACKUP_DISKS=s3,local`.
3. Disk daxili structure: `Paylo/2026-05-24-01-45-00.zip`.

Backup zip içindəkilər:
- `dump.sql` — MySQL dump (`useSingleTransaction`, no table lock).
- Tətbiq fayl tree (vendor + node_modules istisna).

---

## 12. Test edilməsi

Deploy-dan sonra:

```bash
# 1) HTTP → HTTPS redirect
curl -I http://app.paylo.az/   # 301 → https

# 2) Health check
curl https://app.paylo.az/up   # 200 OK

# 3) Login səhifəsi
curl -I https://app.paylo.az/login   # 200

# 4) API ping
curl -I https://app.paylo.az/api/v1/auth/login   # 405 (GET deyil), normal

# 5) Schedule
sudo -u www-data php artisan schedule:list

# 6) Queue
sudo supervisorctl status

# 7) Settlement reconcile — manual sınaq
sudo -u www-data php artisan loyalty:settlement-reconcile --for=yesterday --dry-run
# > exit code 0 (mismatch yoxdur)

# 8) Sentry test
sudo -u www-data php artisan sentry:test
```

---

## 13. Rollback

Deploy uğursuz olarsa:

```bash
cd /var/www/paylo
git log --oneline -5                  # son commit-ləri gör
git reset --hard <PREVIOUS_COMMIT>
sudo -u www-data bash deploy/deploy.sh
```

Migration rollback:
```bash
sudo -u www-data php artisan migrate:rollback --step=1
```

Database tam restore (backup-dan):
```bash
# Backup zip-i lokala çıxar
unzip storage/app/Paylo/2026-05-23-01-45-00.zip -d /tmp/restore
# DB recover
mysql -u root paylo < /tmp/restore/db-dumps/mysql-paylo.sql
```

---

## Production checklist tamamlanması

Bu sənədin axırında işarələnir — bütün addımlar üzərindən keçəndə:

- [x] HTTPS forced — `AppServiceProvider::boot` + Nginx redirect + Let's Encrypt
- [x] Session driver Redis-ə keçdi (`SESSION_DRIVER=redis`)
- [x] Queue Redis-ə keçdi (`QUEUE_CONNECTION=redis`)
- [x] Cache Redis-ə keçdi (`CACHE_STORE=redis`)
- [x] `config:cache && route:cache && view:cache` — `deploy/deploy.sh`-da hər deploy-da
- [x] Sentry integration — `bootstrap/app.php` reportable + `config/sentry.php`
- [x] Daily database backup — `spatie/laravel-backup` schedule + AES-256 şifrəli zip
- [x] Settlement reconciliation job — `loyalty:settlement-reconcile` (Sprint 2-dən)
- [ ] Bucket expiration job — `loyalty:expire-buckets` skeleton (`future`, README-də qeyd)
