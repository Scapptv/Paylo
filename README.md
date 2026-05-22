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
