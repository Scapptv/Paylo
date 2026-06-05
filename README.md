# Paylo — Monolit Modular Platform

Vahid **Laravel 11 + Vue 3 (Inertia.js)** monolit, **modular DDD** prinsipi üzərində qurulub. Hər rol üçün ayrıca bir modul, lakin hamısı eyni database, eyni ledger, eyni `users` cədvəlindən istifadə edir. Login-dən sonra istifadəçi öz roluna uyğun panelə yönləndirilir.

```
┌──────────────────────────────────────────────────────────────┐
│                          Paylo (monolith)                     │
├──────────────────────────────────────────────────────────────┤
│  app/                                                         │
│    Core/            ← shared domain (Ledger, Bucket, User)   │
│    Modules/                                                   │
│      Admin/         ← admin paneli (global visibility)        │
│      Merchant/      ← merchant scoped panel                   │
│      Cashier/       ← shift / lookup                          │
│      Pos/           ← POS kassir interface                    │
│      User/          ← müştəri wallet                          │
│      Auth/          ← login + role routing                    │
│  resources/js/                                                │
│    modules/         ← hər rol üçün ayrı Vue səhifələri        │
│    shared/          ← ümumi komponentlər (Button, Pill, ...)  │
│    layouts/         ← AdminLayout, MerchantLayout, ...        │
└──────────────────────────────────────────────────────────────┘
```

## Əsas Prinsiplər

1. **Vahid ledger** — bütün bonus hərəkətləri immutable, append-only.
2. **Per-merchant bucket** — bonus yalnız qazanıldığı merchant-da xərclənir.
3. **Role-based routing** — `/login` sonrası `roles` cədvəlinə baxılaraq müvafiq dashboard-a yönləndirmə.
4. **Module isolation** — hər modul öz Service Provider-i, route-ları, controller-ləri, Policy-ləri ilə.
5. **Shared kernel** — `app/Core` modulları arasında ortaq dil (Money, BonusValue, MerchantId).

## Rollər və Yönləndirmələr

| Rol | Login sonrası | Modul |
|---|---|---|
| `admin` | `/admin/dashboard` | `Modules\Admin` |
| `merchant_owner` | `/merchant/dashboard` | `Modules\Merchant` |
| `cashier` | `/cashier/shift` | `Modules\Cashier` |
| `pos_terminal` | `/pos/sale` | `Modules\Pos` |
| `customer` | `/wallet` | `Modules\User` |

## Quraşdırma

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev
php artisan serve
```

Default test istifadəçilər `database/seeders/UserSeeder.php` daxilindədir.

## Gündəlik (scheduled) əməliyyatlar

`routes/console.php`-də qeydiyyatdan keçmiş command-lar (production cron tələb edir: `php artisan schedule:work`):

| Command | Cədvəl | Məqsəd | Status |
|---|---|---|---|
| `loyalty:settlement-reconcile --for=yesterday` | Hər gün 02:00 | Bucket counter-ləri ledger toplamı ilə müqayisə edir; mismatch → audit log + exit 1 (alerting) | ✅ Tam implementasiya |
| `loyalty:expire-buckets` | Hər gün 03:00 | Vaxtı keçmiş bonusu `Expire` entry kimi yazıb sıfırlayır | ⏳ Skeleton (`future`) — biznes qaydası dəqiqləşdikdə |

Settlement reconcile manual yoxlama üçün:

```bash
# Bütün bucket-ləri yoxla (manual full audit)
php artisan loyalty:settlement-reconcile --for=all

# Tek bir merchant
php artisan loyalty:settlement-reconcile --for=today --merchant=42

# Audit log yazma, yalnız konsolda göstər
php artisan loyalty:settlement-reconcile --for=yesterday --dry-run
```

## MVP scope və `future` etiketli funksiyalar

Aşağıdakı funksiyalar **MVP-də yoxdur**; UI link-ləri ya gizlədilib, ya "Tezliklə" badge ilə işarələnib. Audit qərarına əsasən sənədləşdirilir:

- **Email verification** — `Api-6` qərarı ilə silindi. `User MustVerifyEmail` implement etmir, register endpoint generic 200 qaytarır. Future: `MustVerifyEmail` trait, queued mail template, `/api/v1/auth/verify-email` endpoint.
- **Password reset** — login səhifəsi `canResetPassword=true` ötürür, lakin route mövcud deyil. Future: standard Laravel password reset (signed link + token).
- **Admin CRUD (merchant/user)** — read-only by design. Yalnız `reverseTransaction` admin əməli vardır. Future: ehtiyac yaranarsa, audit log + 2FA ilə qorunmuş CRUD.
- **Merchant paneli geniş funksiyaları** — müştəri search, manual adjustment UI, branch CRUD, cashier CRUD. Hazırda yalnız dashboard. Future: merchant_owner üçün tam idarəetmə.
- **ExpireBucketsCommand tam implementasiya** — `expire_after_days` config-i var (365 gün), lakin command no-op. Future: `last_activity_at + expire_after_days < now` üçün `Expire` entry yaz, bucket balansını azalt.
- **`expiring_soon` hesablama qaydası** — `/api/v1/wallet` cavabındakı `expiring_soon` məbləği `last_activity_at < (now - 335 gün)` olan bucket-lərin balansı kimi hesablanır (audit Api-10). MVP-də faktiki expiration job işləmir, ona görə bu yalnız "tezliklə bitir" UI rozeti üçün hint dəyəridir. ExpireBucketsCommand tam tətbiq olunduqda hesablanma onunla uyğunlaşdırılacaq.
- **Wallet entries filter** — `recent_entries` `/wallet`-də sadəcə son 10 yazıdır; tam filtr (`type`, `merchant_id`, `from`, `to`, cursor) `GET /api/v1/history` endpoint-i ilə dəstəklənir (audit Usr-2/Api-10). Mobile-da "tarixçə" ekranı bu endpoint-i çağırır.
- **Admin nav "coming soon" linklər** — bəzi sidebar element-ləri `href="#"` (FE-3); Sprint 2-də "Tezliklə" badge ilə işarələnir.
