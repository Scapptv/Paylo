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

- [ ] HTTPS forced (`APP_URL=https://...`)
- [ ] Session driver Redis-ə keç (multi-instance üçün)
- [ ] Queue Redis-ə keç
- [ ] Cache Redis-ə keç
- [ ] `php artisan config:cache && route:cache && view:cache`
- [ ] Sentry və ya Bugsnag integration
- [ ] Daily database backup
- [ ] Settlement reconciliation job (gündəlik)
- [ ] Bucket expiration job (gündəlik)
