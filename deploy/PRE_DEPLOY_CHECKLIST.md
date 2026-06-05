# Paylo Backend — Pre-Deploy Checklist & Hazırlıq Raportu

**Tarix:** 2026-05-25
**Status:** ✅ Lokal pre-deploy hazırlıq tamamlandı. VPS infrastructure gözləyir.

---

## ✅ Tamamlanan lokal hazırlıq

| Addım | Status | Detal |
|---|---|---|
| Backend test suite | ✅ | **248/248 PASS** (835 assertion) |
| Mobile test suite | ✅ | **22/22 PASS** (Sprint 9-da bağlandı) |
| `composer validate --strict` | ✅ | composer.json + lock konsistent |
| `composer audit` | ✅ | Symfony 8 CVE-si yeniləndi → **0 vulnerability** |
| Production asset build (`npm run build`) | ✅ | Vite manifest yenidir |
| `deploy/deploy.sh` syntax | ✅ | `bash -n` təmiz |
| APP_KEY generation | ✅ | `deploy/.env.production.local`-da hazırdır |
| DB_PASSWORD, REDIS_PASSWORD, BACKUP_ARCHIVE_PASSWORD | ✅ | Generate olunub və yazılıb |
| `.gitignore` — secret-ləri exclude | ✅ | `deploy/.env.production.local`, `deploy/.secrets` |

## 📋 Layihənin tam inventarı

- **45 route** registered (12 admin, 14 API/v1, 7 merchant, 4 POS, 3 auth, 5 other)
- **10 migration** — fresh DB-də hamısı icra olunur
- **`up` health-check endpoint** — `GET /up` Laravel default
- **2 scheduled command** — `loyalty:settlement-reconcile` (02:00), `loyalty:expire-buckets` (03:00)
- **3 backup command** — `backup:clean` (01:30), `backup:run` (01:45), `backup:monitor` (05:00)

---

## 🚀 VPS alındıqda — addım-addım

### 1. Server hazırlığı (root SSH ilə)

```bash
# Sistem
sudo apt update && sudo apt upgrade -y

# Lazımi paketlər
sudo apt install -y nginx mysql-server redis-server supervisor certbot \
    python3-certbot-nginx git curl unzip \
    php8.2 php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis php8.2-mbstring \
    php8.2-xml php8.2-zip php8.2-curl php8.2-gd php8.2-intl php8.2-bcmath

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### 2. MySQL setup

```bash
sudo mysql_secure_installation

sudo mysql <<'SQL'
CREATE DATABASE paylo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'paylo_app'@'localhost' IDENTIFIED BY '4579c5531a3d8ad09510ae563cc04461d9137af763533777';
GRANT ALL PRIVILEGES ON paylo.* TO 'paylo_app'@'localhost';
FLUSH PRIVILEGES;
SQL
```

### 3. Redis setup

```bash
sudo nano /etc/redis/redis.conf
# Uncomment və dəyişdir:
#   requirepass 7c743615390bd0c084d43793911b1bd600e12ab14488f170
#   bind 127.0.0.1 ::1
#   appendonly yes

sudo systemctl restart redis
redis-cli -a "7c743615390bd0c084d43793911b1bd600e12ab14488f170" ping
# Gözlənilən cavab: PONG
```

### 4. Kod deploy

```bash
sudo mkdir -p /var/www/paylo
sudo chown -R www-data:www-data /var/www/paylo

# Repo clone (öz Git URL-ini istifadə et)
sudo -u www-data git clone https://github.com/yourorg/paylo.git /var/www/paylo
cd /var/www/paylo

# .env yerləşdir
sudo -u www-data cp deploy/.env.production.local .env

# CHANGE ME hissələrini doldur:
sudo -u www-data nano .env
# - MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD (SMTP gateway)
# - SENTRY_LARAVEL_DSN (sentry.io project-dən)
# - BACKUP_NOTIFICATION_EMAIL (öz admin email-in)

# İlk deploy
sudo -u www-data bash deploy/deploy.sh
```

`deploy.sh` avtomatik:
- `composer install --no-dev`
- `npm ci && npm run build`
- `php artisan migrate --force`
- `config:cache && route:cache && view:cache`
- `queue:restart`

### 5. Nginx + SSL

```bash
sudo cp deploy/nginx.conf.example /etc/nginx/sites-available/paylo
sudo nano /etc/nginx/sites-available/paylo
# `server_name app.paylo.az;` təsdiqlə (artıq qoyulmuş ola bilər)

sudo ln -s /etc/nginx/sites-available/paylo /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default

# SSL — Let's Encrypt
sudo certbot --nginx -d app.paylo.az --redirect --agree-tos -m admin@paylo.az -n

sudo nginx -t && sudo systemctl reload nginx
```

### 6. Queue Worker (Supervisor)

```bash
sudo cp deploy/supervisor.conf.example /etc/supervisor/conf.d/paylo-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start paylo-worker:*
sudo supervisorctl status paylo-worker:*
```

### 7. Scheduler cron

```bash
sudo -u www-data crontab -e
# Yapışdır:
* * * * * cd /var/www/paylo && php artisan schedule:run >> /dev/null 2>&1
```

### 8. Smoke test

```bash
# Health check
curl -I https://app.paylo.az/up
# Gözlənilən: HTTP/2 200

# Login səhifəsi
curl -I https://app.paylo.az/login
# Gözlənilən: HTTP/2 200

# Scheduler list
sudo -u www-data php artisan schedule:list

# Queue worker
sudo supervisorctl status

# Settlement reconcile dry-run
sudo -u www-data php artisan loyalty:settlement-reconcile --for=yesterday --dry-run

# Sentry test
sudo -u www-data php artisan sentry:test
```

---

## 🔐 Saxlanılan secret-lər (təkrar yaratma əmrləri)

Bütün lokal secret-lər `deploy/.env.production.local`-da. **Bu fayl Git-ə commit OLUNMUR** (`.gitignore`).

| Secret | Komand |
|---|---|
| `APP_KEY` | `php artisan key:generate --show` |
| `DB_PASSWORD` | `php -r "echo bin2hex(random_bytes(24));"` |
| `REDIS_PASSWORD` | `php -r "echo bin2hex(random_bytes(24));"` |
| `BACKUP_ARCHIVE_PASSWORD` | `php -r "echo bin2hex(random_bytes(20));"` |

Hələ generate olunmayanlar (user təyin edir):
- `SENTRY_LARAVEL_DSN` — sentry.io → Projects → Laravel → DSN kopyala
- `MAIL_*` — SMTP provider (Mailgun, Postmark, AWS SES, Resend və s.)
- `AWS_*` — opsional S3 backup

---

## 📦 İlk deploy üçün son komand zənciri

VPS-ə SSH olduqdan sonra (yuxarıdakı 1–7 addımları tamamlandıqdan sonra):

```bash
cd /var/www/paylo
sudo -u www-data bash deploy/deploy.sh
sudo supervisorctl reload
sudo nginx -t && sudo systemctl reload nginx
curl https://app.paylo.az/up
```

**Cavab `OK` (HTTP 200) olarsa deploy tamamlanmışdır.**

---

## ⚠️ Production sonrası ilk gün

1. **Admin user yarat:**
   ```bash
   sudo -u www-data php artisan tinker
   >>> User::create(['name' => 'Admin', 'email' => 'admin@paylo.az', 'password' => bcrypt('STRONG_PASS'), 'role' => UserRole::Admin, 'is_active' => true])
   ```
2. **Seeder (MerchantSeeder):** Production-da SƏ NDLƏN — backend test data yaradır.
3. **İlk merchant manual əlavə:** Admin panelinə `/admin/merchants/create` daxil ol, mağaza əlavə et.
4. **Sentry test event** alındığını yoxla.
5. **`/storage/logs/laravel.log`** çıxış edən xətaları izlə (ilk 24 saat aktiv monitorinq).
6. **Backup sınağı:**
   ```bash
   sudo -u www-data php artisan backup:run
   sudo -u www-data php artisan backup:list
   ```
   `storage/app/Paylo/*.zip` faylı yaranmalıdır.

---

## ✅ Yekun

**Lokal hazırlıq tam.** Komand vermək kifayətdir:

```bash
# Bütün hazırlanmış artefaktları arxivə yığıb upload edə bilərsən
tar czf paylo-deploy-2026-05-25.tar.gz \
  --exclude=node_modules \
  --exclude=vendor \
  --exclude=tests \
  --exclude=storage/framework \
  --exclude=.git \
  scapp-loyalty/

# Yaxud daha sadə: VPS-də `git clone` + `bash deploy/deploy.sh`
```

Bütün test-lər keçir, asset-lər build olunub, secret-lər generate olunub, deploy.sh syntax təmizdir. **Kod prod-a hazırdır.**
