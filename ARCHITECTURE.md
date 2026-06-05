# Paylo — Architecture Reference

## 1. Vahid monolit, modular daxili struktur

Layihə **bir Laravel proyektidir** — `composer.json`, `.env`, `artisan` hamısı vahiddir. Lakin daxildə kod **DDD-style modular** ayrılıb:

```
app/
├── Core/                       ← shared kernel: heç bir modula bağlı deyil
│   ├── Models/                 User, Merchant, Branch, Bucket, LedgerEntry, Transaction
│   ├── Enums/                  UserRole, LedgerEntryType
│   ├── ValueObjects/           BonusValue (integer-əsaslı, float xətasız)
│   ├── Services/               LedgerService (atomic earn/redeem/refund)
│   └── Support/                ModuleServiceProvider (abstract)
│
├── Modules/                    ← hər biri öz dünyasında, lakin Core-u paylaşır
│   ├── Auth/                   login form, RoleRouter
│   ├── Admin/                  global visibility (Dashboard, Ledger, Merchants)
│   ├── Merchant/               merchant-scoped (yalnız öz mağazası)
│   ├── Cashier/                shift overview
│   ├── Pos/                    sale flow (lookup → preview → complete)
│   └── User/                   müştəri wallet
│
└── Http/Middleware/
    ├── EnsureRole              `role:admin,merchant_owner`
    ├── EnsureMerchantScope     merchant_id-i request-ə bind edir
    └── HandleInertiaRequests   global auth/flash share
```

## 2. Rol → Panel mapping

```
┌────────────────────┬──────────────────────────┬─────────────────────┐
│ Role               │ Login sonrası redirect   │ Modul               │
├────────────────────┼──────────────────────────┼─────────────────────┤
│ admin              │ /admin/dashboard         │ Modules\Admin       │
│ merchant_owner     │ /merchant/dashboard      │ Modules\Merchant    │
│ merchant_staff     │ /merchant/dashboard      │ Modules\Merchant    │
│ cashier            │ /cashier/shift           │ Modules\Cashier     │
│ pos_terminal       │ /pos/sale                │ Modules\Pos         │
│ customer           │ /wallet                  │ Modules\User        │
└────────────────────┴──────────────────────────┴─────────────────────┘
```

Redirect qaydası **bir yerdə** — `app/Core/Enums/UserRole.php::homeRoute()`. Yeni rol əlavə olunsa, yalnız bu fayl dəyişir.

## 3. Ledger semantikası — ƏN MÜHÜM HİSSƏ

### 3.1 Immutable append-only

`LedgerEntry` modelində `boot()` daxilində:

```php
static::updating(function (): bool {
    throw new \RuntimeException('Ledger entry-lər immutable-dır. Update qadağandır.');
});

static::deleting(function (): bool {
    throw new \RuntimeException('Ledger entry-lər immutable-dır. Delete qadağandır.');
});
```

Refund / reversal **mövcud entry-i dəyişməz**, yeni entry yaradar və `reverses_id` ilə original-a istinad edər.

### 3.2 Per-merchant bucket

Hər `(user_id, merchant_id)` cütü üçün **bir** bucket. Cross-merchant transfer yoxdur — bonus yalnız qazanıldığı merchant-da xərclənir.

```sql
SELECT balance FROM buckets WHERE user_id = ? AND merchant_id = ?;
```

UI cəm balans göstərir:

```sql
SELECT SUM(balance) FROM buckets WHERE user_id = ?;
```

### 3.3 Atomic operations

`LedgerService` daxilində hər metod:

1. `DB::transaction()` açır
2. `Bucket::lockForUpdate()` — concurrent earn/redeem-i bloklamır, sıraya qoyur
3. Bucket counter-lərini yeniləyir
4. `LedgerEntry::create()` ilə yeni yazı atır
5. Transaction commit

Race condition mümkün deyil çünki MySQL `SELECT ... FOR UPDATE` cədvəl səviyyəsində row lock qoyur.

## 4. Modul Service Provider mexanizmi

Hər modulun öz `Providers/XServiceProvider.php`-i var və `bootstrap/app.php` daxilində qeydiyyatdan keçir:

```php
->withProviders([
    App\Modules\Auth\Providers\AuthServiceProvider::class,
    App\Modules\Admin\Providers\AdminServiceProvider::class,
    // ...
])
```

Hər biri `ModuleServiceProvider` baza sinfindən gəlir və avtomatik öz `Routes/web.php`-ni `web` middleware ilə yükləyir.

## 5. Frontend — Vue 3 + Inertia.js

Inertia "SPA without API" yanaşmasıdır:
- Backend `Inertia::render('Admin/Dashboard', [...])` qaytarır
- Frontend `resources/js/Pages/Admin/Dashboard.vue` faylını render edir
- Server-side props avtomatik prop kimi component-ə daxil olur
- `usePage().props.auth.user` — bütün səhifələrdə avtomatik mövcud (HandleInertiaRequests vasitəsilə)

### Page → Layout matching

```
Pages/Auth/Login.vue       → öz daxili layout (split-screen)
Pages/Admin/*.vue          → AdminLayout.vue  (sidebar + crumbs)
Pages/Merchant/*.vue       → MerchantLayout.vue (merchant card + sidebar)
Pages/Cashier/*.vue        → CashierLayout.vue (top nav)
Pages/Pos/Sale.vue         → CashierLayout.vue (eyni)
Pages/User/Wallet.vue      → MobileLayout.vue (bottom nav)
```

## 6. Authorization layers

```
┌─────────────────────────────────────────────────────────┐
│ HTTP request                                            │
└───────────────┬─────────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────────────────────┐
│ 1. auth middleware (Laravel)                            │
│    → login yoxdursa /login-ə yönlət                     │
└───────────────┬─────────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────────────────────┐
│ 2. role middleware (EnsureRole)                         │
│    → user.role bu modul üçün uyğun deyilsə 403          │
└───────────────┬─────────────────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────────────────────┐
│ 3. merchant.scope middleware (EnsureMerchantScope)      │
│    → merchant_owner/cashier üçün user.merchant_id-i     │
│      request-ə bağlayır → controller-də query filter    │
└───────────────┬─────────────────────────────────────────┘
                │
                ▼
        Controller → Service → Model
```

## 7. Test test test

Sınaq strategiyası:
- **LedgerService** — unit test (immutable, overdraft prevention, per-merchant isolation)
- **Controller** — feature test (rol → status code, merchant scoped data leak yoxdur)
- **POS sale flow** — integration test (lookup → preview → complete tam ssenari)

```bash
php artisan test
```

## 8. Genişləndirmə qaydaları

### Yeni rol əlavə etmək
1. `Core/Enums/UserRole.php` — yeni case
2. `homeRoute()` daxilində redirect route
3. Yeni modul yaratmaq və `bootstrap/app.php`-də register

### Yeni modul əlavə etmək
1. `app/Modules/X/` qovluğu
2. `Http/Controllers`, `Routes/web.php`, `Providers/XServiceProvider.php`
3. `bootstrap/app.php` → withProviders
4. `composer.json` autoload-da yox — psr-4 mövcud `App\Modules\` namespace-ində avtomatik tutur

### Yeni ledger entry type
1. `Core/Enums/LedgerEntryType.php` — yeni case
2. `LedgerService`-də yeni method
3. Migration: enum-a yeni dəyər əlavə (yaxud string-ə dəyişmək)

## 9. Performans qeydləri

- Ledger böyük cədvəl olacaq → composite index (`user_id`, `merchant_id`, `created_at`)
- Admin dashboard üçün read replica + materialised view (gələcəkdə)
- Bucket balansı denormalized — hər read üçün ledger-i scan etməyə ehtiyac yoxdur
- Frontend Inertia partial reload (`only: [...]`) ilə optimize edilir

## 10. Production checklist

Bütün addımlar `DEPLOYMENT.md`-də sənədləşdirilib; `.env.production.example`
+ `deploy/` qovluğu (nginx, supervisor, cron, deploy.sh) hazır şablonlardır.

- [x] HTTPS forced — `AppServiceProvider::boot()` `URL::forceScheme('https')` (APP_URL https-dirsə) + Nginx HTTP→HTTPS redirect + `TRUSTED_PROXIES` env. Let's Encrypt SSL.
- [x] Session driver Redis-ə keçdi — `.env: SESSION_DRIVER=redis` (`config/database.php` redis section default).
- [x] Queue Redis-ə keçdi — `.env: QUEUE_CONNECTION=redis` + Supervisor worker (`deploy/supervisor.conf.example`).
- [x] Cache Redis-ə keçdi — `.env: CACHE_STORE=redis` (ayrı DB index 1).
- [x] `config:cache && route:cache && view:cache` — `deploy/deploy.sh` hər deploy-da avtomatik icra edir + `event:cache`.
- [x] Sentry integration — `sentry/sentry-laravel` paketi; `bootstrap/app.php` `reportable` handler `Sentry\Laravel\Integration::captureUnhandledException`; PII default-da göndərilmir (`SENTRY_SEND_DEFAULT_PII=false`).
- [x] Daily database backup — `spatie/laravel-backup` (`backup:run` 01:45, `backup:clean` 01:30, `backup:monitor` 05:00); MySQL dump `useSingleTransaction` ilə (lock-suz); AES-256 şifrəli zip; off-site disk üçün `BACKUP_DISKS=s3,local` dəstəyi.
- [x] Settlement reconciliation job (gündəlik) — `loyalty:settlement-reconcile --for=yesterday` daily at 02:00, alert on exit 1 (mismatch).
- [ ] Bucket expiration job (gündəlik) — `loyalty:expire-buckets` skeleton; biznes qaydası dəqiqləşdikdə tam implement.

## 11. QR təhlükəsizliyi — Static vs Rotating (security invariant)

Sistemdə iki ayrı QR konsepti var və **bunlar bir-biri ilə qarışdırılmamalıdır**:

### 11.1 İki növ QR

| Növ | Mənbə | Ömrü | İstifadə yeri |
|---|---|---|---|
| `customer_qr` (static) | `User::saving` boot listener avtomatik generasiya edir, hesabın ömrü boyu sabit qalır | Daimi (yalnız anonimləşdirmə zamanı dəyişir) | Mobile app daxili saxlama; rotating token üçün identifier; admin recovery |
| `qr1.{customer_qr}.{exp_unix}.{hmac16}` (rotating) | `RotatingQrService::generate()` (HMAC-SHA256 + expiry) | TTL: 30 saniyə | Cashier scan; replay protection (`markUsed`) |

### 11.2 Static QR exposure prinsipi

**Static `customer_qr` heç bir API və ya POS response-da qaytarılmamalıdır.** Aşağıdakı istisnalar yeganə icazəli istifadə nöqtələridir:

1. **Mobile app daxili saxlama** — `POST /api/v1/auth/login` və `POST /api/v1/auth/register` cavabında `user.customer_qr` field-i bir dəfə gəlir. Mobile bunu lokal secure storage-da (Keychain / Keystore) saxlayır və başqa heç bir yerdə görsənmir.
2. **Server tərəfdə rotating token generasiyası** — `RotatingQrService::generate(user, ttl)` daxili olaraq `user->customer_qr`-i HMAC payload-una qoyur. Bu dəyər heç vaxt response-a çıxmır; yalnız imzalanmış rotating token qaytarılır.
3. **Admin recovery / debug** — yalnız `admin` rolu altında, admin panel daxilində, audit log ilə (gələcək feature).

### 11.3 Konkret yasaqlar

Audit P-2 və Api-5 nəticəsi olaraq aşağıdakı endpoint-lər static QR-i **qaytarmamalıdır** (regression testləri ilə qorunur):

- `GET /pos/customer/{qr}` (POS lookup) — `customer.qr` field-i response-dan çıxarılıb. Cashier müştərinin static QR-ini görmür.
- `GET /api/v1/qr` (mobile rotating QR endpoint) — yalnız `qr_value` (rotating token), `expires_at`, `ttl` qaytarır. `static_qr` field-i mövcud deyil.
- Bütün audit/log yazıları **plain-text QR yox**, `hash('sha256', $qr)` saxlayır (`pos.customer.lookup` log struktur nümunəsidir).

### 11.4 Niyə bu prinsip vacibdir

- **Long-lived secret** — static QR hesabın ömrü boyu eynidir; bir dəfə leak olarsa, attacker o hesab adından dayanmadan ödəniş ala bilər (rotating-də 30 saniyə pəncərə var).
- **Replay protection rotating-də** — `markUsed` cache layer-i hər valid token-i 60 saniyə bloklayır; static-də belə müdafiə yoxdur.
- **Log/network exposure** — REST API response-ları proxy, CDN, mobile log frameworks tərəfindən cache və ya disk-ə yaza bilir. Static QR oraya düşməməlidir.

### 11.5 Web wallet istisna (Usr-4)

`Pages/User/Wallet.vue` müştərinin **öz** static QR-ini ekranda göstərir (`{{ customer.qr }}`). Bu **eyni-istifadəçi self-exposure** halıdır: istifadəçi öz hesabının QR-ini öz cihazında görür. Üçüncü tərəf cashier və ya server endpoint-i bu axına daxil deyil. Mobile-də isə artıq rotating QR istifadə olunur.

İstinad:
- POS implementation: `app/Modules/Pos/Http/Controllers/SaleController.php:lookupCustomer`
- Rotating service: `app/Modules/Api/Services/RotatingQrService.php`
- Mobile generate endpoint: `app/Modules/Api/Http/Controllers/V1/QrController.php`
