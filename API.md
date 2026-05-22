# Paylo REST API (v1)

Flutter mobile client üçün customer-facing REST API. Web Inertia tərəfindən tam izolyasiyalı: bütün route-lar `app/Modules/Api/` daxilindədir, ayrı middleware stack istifadə edir.

**Base URL:** `http://localhost:8000/api/v1` (dev) / `https://api.paylo.az/v1` (prod)
**Auth:** Laravel Sanctum bearer token, ability `customer`
**Format:** JSON / UTF-8 / ISO-8601 zaman damğaları (+04:00 Asia/Baku)
**Para vahidi:** Bütün məbləğlər **qəpik (integer)** — `2481` = `24.81 AZN`. Currency həmişə `"AZN"`.

---

## 1. Authentication

### `POST /auth/login` — public, throttle 5/min/IP+email

**Request**
```json
{
  "email":       "aysel@gmail.com",
  "password":    "password",
  "device_name": "iPhone 15 Pro"
}
```

**Response 200**
```json
{
  "token":      "1|pBDBR0LvD4FBforx7msOEVx46OLZwAbV3V3r0AMk2ea924e8",
  "expires_at": "2026-06-16T10:17:54+04:00",
  "user": {
    "id": 8, "name": "Aysel Hüseynova", "email": "aysel@gmail.com",
    "phone": "+994501234567", "role": "customer",
    "customer_qr": "qr_clsztufrcaaa", "email_verified": false
  }
}
```

**Hata cavabları**
| Status | Sənario |
|---|---|
| `422` | `{"errors":{"email":["Yanlış e-poçt və ya şifrə."]}}` — yanlış cred / qeyri-müştəri rol / `is_active=false` |
| `429` | Rate limit. Header: `Retry-After` |

Token ability: yalnız `customer`. Cashier/admin guard-larından ayrıdır.

### `POST /auth/register` — public, throttle 5/min

```json
{
  "name":  "Yeni İstifadəçi",
  "email": "yeni@example.com",
  "phone": "+994551112233",
  "password": "qwerty12",
  "password_confirmation": "qwerty12",
  "locale": "az",
  "device_name": "Pixel 8"
}
```

Cavab `201` — `login` ilə eyni format. Email verification linki avtomatik göndərilir (`Registered` event).

**curl nümunəsi**
```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/register \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Yeni İstifadəçi",
    "email": "yeni@example.com",
    "phone": "+994551112233",
    "password": "qwerty12",
    "password_confirmation": "qwerty12",
    "locale": "az",
    "device_name": "Pixel 8"
  }'
```

**Hata cavabları**
| Status | Sənario |
|---|---|
| `422` | Validation (email unique, password confirm, phone format) |
| `429` | Rate limit. Header: `Retry-After` |

### `POST /auth/logout` — auth, ability `customer`

Cari bearer tokeni revoke edir.

```json
{"message":"Logged out."}
```

### `POST /auth/logout-all` — auth, ability `customer`

İstifadəçinin bütün cihazlarındakı tokenləri revoke edir.

---

## 2. Profile

### `GET /me`

```json
{"user":{ "id":8, "name":"...", "email":"...", "phone":"...", "role":"customer", "customer_qr":"qr_...", "email_verified":false }}
```

### `PUT /me`

```json
{"name":"Aysel H.","phone":"+994501234567","locale":"en"}
```

Yalnız göndərilən sahələr yenilənir (sometimes rule). `locale` validate olunur ancaq hələ user cədvəlində persist olunmur.

### `PUT /me/password`

```json
{
  "current_password": "password",
  "password": "yeniSifre123",
  "password_confirmation": "yeniSifre123"
}
```

Cari token saxlanır, **digər cihazlardakı bütün tokenlər silinir**. AuditLogger `api.profile.password_changed` event-i yazır.

### `DELETE /me` — hesab silmə (GDPR/KVKK uyğun)

```json
{"password":"password","confirm":true}
```

Hard-delete YOX. PII sahələri anonimləşir, hesab `is_active=false`, bütün tokenlər və push device-lər silinir. Ledger yazıları (immutable) toxunulmur. AuditLogger `api.profile.deleted` yazır.

---

## 3. Wallet & History

### `GET /wallet` — auth

```json
{
  "total_balance":            2481,
  "total_earned_all_time":    2481,
  "total_redeemed_all_time":  0,
  "expiring_soon":            0,
  "buckets_count":            4,
  "buckets": [
    {
      "id": 4, "balance": 699, "earned_total": 699,
      "redeemed_total": 0, "expired_total": 0,
      "last_activity_at": "2026-05-17T10:17:43+04:00",
      "merchant": {"id":5,"code":"m_055","name":"Pasha Cafe","category":"restaurant","tier":"standard"}
    }
  ],
  "recent_entries": [ /* son 10 LedgerEntry */ ],
  "currency": "AZN"
}
```

`expiring_soon` = növbəti **335 gün** ərzində bitəcək balans (integer qəpik).

### `GET /history` — auth, cursor pagination

**Query parameters**
| Param | Type | Qeyd |
|---|---|---|
| `cursor` | string | əvvəlki response-dan `next_cursor`/`prev_cursor` |
| `type` | string | `earn` \| `redeem` \| `expire` \| `adjust` \| `refund` |
| `merchant_id` | int | merchant-a görə filter |
| `from` | date | `YYYY-MM-DD`, inclusive (startOfDay) |
| `to` | date | `YYYY-MM-DD`, inclusive (endOfDay) |
| `limit` | int | 1–50, default 20 |

**Response**
```json
{
  "data": [ /* LedgerEntryResource[] */ ],
  "next_cursor": "eyJpZCI6MjEsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0",
  "prev_cursor": null,
  "has_more": true
}
```

### `GET /buckets/{bucket}/history` — auth

Eyni filter dəsti (minus `merchant_id` — bucket sahibindən gəlir).
Bucket sahibi cari user deyilsə **403 Forbidden** qaytarır.

---

## 4. Rotating QR (Cashier üçün scan)

### `GET /qr` — auth, throttle 10/min

```json
{
  "qr_value":   "qr1.qr_clsztufrcaaa.1778998704.40801dc44cecc2cd",
  "expires_at": "2026-05-17T06:18:24+00:00",
  "ttl":        30,
  "static_qr":  "qr_clsztufrcaaa"
}
```

**Format:** `qr1.{user_qr}.{unix_exp}.{hmac16}`
**HMAC:** `substr(hash_hmac('sha256', "{user_qr}.{unix_exp}", APP_KEY), 0, 16)`
**TTL:** 30 saniyə. Hər scan-dan sonra cache-də `qr_used:{hmac}` markered → replay attack qarşısı alınır.

Mobile tərəf hər 25-30 saniyədən bir yenidən çəkir (Flutter `Timer.periodic`).

---

## 5. Push Notifications

### `POST /push/register` — auth

```json
{
  "token":         "<fcm-or-apns-token>",
  "platform":      "ios",
  "app_version":   "1.0.0",
  "device_model":  "iPhone 15 Pro"
}
```

`updateOrCreate(['token' => ...])` → eyni cihazdan təkrar registration idempotentdir. `last_seen_at` yenilənir.

### `DELETE /push/register` — auth

```json
{"token":"<fcm-or-apns-token>"}
```

---

## 6. Cross-cutting

### Authorization header

Bütün auth route-larda:
```
Authorization: Bearer 1|pBDBR0LvD4FBforx7msOEVx46OLZwAbV3V3r0AMk2ea924e8
Accept: application/json
```

### Throttling

| Qrup | Limit |
|---|---|
| Public auth (`login`, `register`) | 5 / dəq / `email+ip` |
| Auth endpoints (default) | 60 / dəq / token |
| QR generation | 10 / dəq / token |

429 cavabında `Retry-After: <saniyə>` header-i.

### CORS

`config/cors.php` `paths: ['api/*', 'sanctum/csrf-cookie']`, origins env-driven (`CORS_ALLOWED_ORIGINS`, vergüllə ayrılır).

**Default davranış (env boş və ya təyin olunmayıb):** wildcard `*` DEYİL. `SANCTUM_STATEFUL_DOMAINS`-dakı hər host üçün `http://` və `https://` origin avtomatik törədilir. Yəni admin panel hosti `SANCTUM_STATEFUL_DOMAINS=admin.paylo.az`-də göstərilibsə əlavə CORS konfiqurasiyası lazım deyil.

Wildcard istəyirsənsə açıqca `CORS_ALLOWED_ORIGINS=*` yaz (yalnız local dev — `supports_credentials=true` ilə brauzer onsuz da rədd edəcək).

Mobile native client üçün CORS lazım deyil — yalnız web-based debug üçündür.

### Standart error formatı

Validation:
```json
{"message":"...","errors":{"field":["mesaj"]}}
```

403 / 404 / 401 / 429 — Laravel default JSON (bootstrap-da `shouldRenderJsonWhen` aktiv).

### Audit log eventləri

| Event | Tetikleyici |
|---|---|
| `api.auth.login` | uğurlu login |
| `api.auth.login.failed` | yanlış cred / passiv user / qeyri-müştəri |
| `api.auth.login.rate_limited` | 429 trigger |
| `api.auth.register` | qeydiyyat |
| `api.auth.logout` / `logout_all` | manual logout |
| `api.profile.update` | profil yeniləməsi |
| `api.profile.password_changed` | şifrə dəyişikliyi |
| `api.profile.deleted` | hesab anonimləşməsi |
| `api.profile.delete.failed` | yanlış password ilə delete cəhdi |

---

## 7. Status overview

Bütün route-lar (`php artisan route:list --path=api`):

```
POST     api/v1/auth/login            LoginController@store
POST     api/v1/auth/logout           LoginController@destroy
POST     api/v1/auth/logout-all       LoginController@destroyAll
POST     api/v1/auth/register         RegisterController@store
GET      api/v1/me                    ProfileController@show
PUT      api/v1/me                    ProfileController@update
DELETE   api/v1/me                    ProfileController@destroy
PUT      api/v1/me/password           ProfileController@changePassword
GET      api/v1/wallet                WalletController@show
GET      api/v1/history               HistoryController@index
GET      api/v1/buckets/{bucket}/history  HistoryController@forBucket
GET      api/v1/qr                    QrController@generate
POST     api/v1/push/register         PushTokenController@register
DELETE   api/v1/push/register         PushTokenController@destroy
```

---

## 8. Lokal test üçün

### A) Curl ilə (server qaldırıldıqdan sonra)

```bash
# 1. Login
TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"aysel@gmail.com","password":"password","device_name":"curl"}' \
  | jq -r .token)

# 2. Wallet
curl http://localhost:8000/api/v1/wallet \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | jq
```

> **Windows PHP `artisan serve` problemi:** Bəzi mühitlərdə (Hyper-V / WinNAT dynamic port reservations) `artisan serve` `Failed to listen on 127.0.0.1:XXXX` xətası verir. Həll: `netsh int ipv4 show excludedportrange protocol=tcp` çıxışındakı aralıqdan kənar port seç, və ya `php -S 127.0.0.1:8000 -t public public/index.php` istifadə et.

### B) In-process smoke test (port lazımsız)

`scapp-loyalty/test_api.php` — Laravel HTTP kernel-ı ilə birbaşa request emal edir, eyni middleware stack-i. İstifadə:

```bash
cd scapp-loyalty
php artisan db:seed --force   # ilk dəfə
php test_api.php
```

Login + wallet + me + qr + history + logout — hamısı 200.

### Seeded test users

| Email | Şifrə | Rol |
|---|---|---|
| `aysel@gmail.com` | `password` | Customer (8 müştəridən biri) |
| `tural@gmail.com`, `lale@gmail.com`, … | `password` | Customer |
| `cashier@bravo.az` | `password` | Cashier (API üçün YOX — yalnız web Inertia) |
| `admin@paylo.az` | `password` | Admin (API üçün YOX) |

---

## 9. Hələ implementasiya olunmayanlar (roadmap)

- `users.locale` sütunu (validate olunur amma persist yox)
- Cashier scan endpoint: `RotatingQrService::verify()` + `markUsed()`
- FCM/APNS dispatch worker (PushToken qeydləri əsasında)
- Pest feature testləri `tests/Feature/Api/V1/`
- Dedicated `audit` log channel `config/logging.php`-də (hazırda default channel-a fallback)
