# Paylo REST API (v1)

İki istifadə hədəfi, tam izolyasiyalı route group-ları:

1. **Customer mobile API** (Flutter müştəri tətbiqi) — `/api/v1/auth/*`, `/api/v1/me/*`, `/api/v1/wallet`, `/api/v1/history`, `/api/v1/qr`, `/api/v1/push/*`. Token ability `customer`.
2. **POS Integration API** (M2M — POSNET və digər kassir sistemləri) — `/api/v1/pos/*`. Token ability `pos:write`. Detal: [Bölmə 10](#10-pos-integration-api-m2m).

Web Inertia tərəfindən tam izolyasiyalı: bütün route-lar `app/Modules/Api/` daxilindədir, ayrı middleware stack istifadə edir.

**Base URL:** `http://localhost:8000/api/v1` (dev) / `https://api.paylo.az/v1` (prod)
**Auth:** Laravel Sanctum bearer token; ability customer surface üçün `customer`, POS surface üçün `pos:write`.
**Format:** JSON / UTF-8 / ISO-8601 zaman damğaları (+04:00 Asia/Baku)
**Para vahidi:** Bütün məbləğlər **qəpik (integer)** — `2481` = `24.81 AZN`. Currency həmişə `"AZN"`.

---

## 0. JSON shape qaydası (Sprint 8 J-1)

**API və Web Inertia ayrıca naming convention saxlayır** — bu qəsdən dizayn qərarıdır:

| Surface | Convention | Səbəb |
|---|---|---|
| **API (REST, `/api/v1/*`)** | `snake_case` (`total_balance`, `recent_entries`, `customer_qr`) | REST API ekosistem standartı; mobile (Flutter) və ya 3rd-party SDK-lar PHP backend ilə naming uyğunluğu gözləyir. |
| **Web Inertia (`/admin`, `/merchant`, `/cashier`, `/user`)** | `camelCase` (`totalBalance`, `recentEntries`, `customerQr`) | JavaScript ekosistem norması; Vue komponentləri prop-larında JS standart. |

**Frontend conversion qadağandır.** Vue komponentinə API call gedirsə (məs mobile app olmayan rare halda Inertia səhifəsindən fetch), nəticəni öz conversion utility-də (`useFormat.js`-də və ya ayrı `apiClient.js`-də) snake→camel-ə çevir. Backend hər iki surface üçün ayrı response qurur — duplikat deyil, fərqli **interface contract**-dır.

**Tək istisna:** `Pinia store` olarsa (real-time updates feature-i), state-i camelCase saxla; API client snake→camel conversion edir.

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

#### Token rotation və `device_name` davranışı (audit Api-13)

Hər uğurlu `login` (və `register`) yeni Sanctum token verir və **eyni `device_name` ilə əvvəlki token-ləri DB-dən silir**. Praktiki nəticələr mobile app üçün:

- **Bir cihaz = bir aktiv token.** "iPhone 15 Pro" device-i ilə ikinci login etsən, birinci token dərhal invalid olur (revoke edilir). 30 günlük TTL də sıfırlanır.
- **Köhnə token client-də cached qala bilər.** Server tərəfdə silinib, lakin app yaddaşda saxlayıb. Belə token-lə hər hansı endpoint çağırışı **401 Unauthorized** qaytarır. App `401` görəndə saxlanılmış token-i təmizləyib istifadəçini yenidən login-ə yönləndirməlidir.
- **Fərqli `device_name`-lər müstəqildir.** "iPhone 15 Pro" və "iPad" eyni hesab üçün iki ayrı aktiv token saxlaya bilər. Hər biri öz cihaz adı ilə ləğv olur.
- **`logout`** yalnız cari istifadə olunan token-i silir (digər cihazlar toxunulmaz).
- **`logout-all`** istifadəçinin BÜTÜN token-lərini silir — şifrə oğurluğu/cihaz itməsi ssenarisi üçün.

Token TTL: `config('sanctum.expiration')` (default 43 200 dəq ≈ 30 gün). TTL bitdikdən sonra Sanctum middleware-i 401 qaytarır — eyni 401-recovery axını tətbiq olunur.

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

Cavab `202 Accepted` — `login` ilə eyni format. Token rotation eyni qaydaya tabedir (yuxarıdakı bölmə). Email verification MVP-də mövcud deyil (audit Api-6 — silindi).

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

### Proaktiv rate-limit header-ləri (Sprint 8 T-1)

Hər `/api/v1/*` cavabında aşağıdakı header-lər qoyulur — mobile client backoff strategiyası üçün:

| Header | Təsvir |
|---|---|
| `X-RateLimit-Limit` | Cari pəncərə üçün icazə verilən maksimum cəhd sayı (default 60). |
| `X-RateLimit-Remaining` | Pəncərənin qalan sorğu sayı. 0-a düşəndə növbəti sorğu 429 qaytaracaq. |
| `X-RateLimit-Reset` | Pəncərə sıfırlanana qədər qalan saniyə (yalnız aktiv pəncərədə). |

Mobile client `Remaining < 5` halında polling intervalını artırmalıdır.

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

---

## 10. POS Integration API (M2M)

POSNET (Python/FastAPI) və ya digər kassir sistemləri ilə inteqrasiya üçün
machine-to-machine endpoint qrupu. Customer mobile API-dən və web Inertia
kassir panelindən tam izolyasiyalı.

**Auth:** Sanctum bearer token, ability `pos:write`.
**Token sahibi:** `pos_terminal` rolunda `User`. `merchant_id` token sahibindən
gəlir — payload-da göndərilməsi qəbul edilmir (silent override yox, qəti scope).
**Throttle:** 120 sorğu/dəq/token.
**Idempotency:** iki qat — (a) `Idempotency-Key` header (cache replay, 24 saat),
(b) domain-level `(merchant_id, receipt_no)` unique constraint.

### 10.1 Token operations

Üç komanda — issuance, listing, revocation. Plain-text token **yalnız bir dəfə**
issuance zamanı göstərilir. Sahibi onu təhlükəsiz yerdə saxlamalıdır (POSNET
tərəfində Vault / .env).

#### Issue

```bash
php artisan pos:issue-token --merchant=m_412 --name=bravo-pos-01
# default: --expires-days=90, ability=pos:write

# Reverse səlahiyyəti də vermək (audit P-4 ekvivalenti — operator istəyəndə):
php artisan pos:issue-token --merchant=m_412 --name=bravo-mgr --include-reverse

# Vaxtı uzun saxlamaq (max 3650 gün):
php artisan pos:issue-token --merchant=m_412 --name=bravo-pos-01 --expires-days=365
```

- `--merchant` məcburi, mağaza kodu (məs. `m_412`).
- `--name` məcburi, terminal və ya inteqrasiya server adı. Eyni adla təkrar
  verilməsi köhnə token-i siləcək (rotation).
- `--expires-days` default 90 — bank M2M token norm.
- `--include-reverse` opsional. Default token YALNIZ sale əməliyyatları üçündür;
  reverse `pos:reverse` ability-li ayrı token tələb edir.

Hər merchant üçün bir "POS Integration" user (`pos@<code>.api`) avtomatik
yaranır; ona bir neçə adlandırılmış token bağlana bilər.

#### List

```bash
# Bütün merchant-lar
php artisan pos:list-tokens

# Konkret merchant
php artisan pos:list-tokens --merchant=m_412

# Yalnız vaxtı keçmişlər (cleanup üçün)
php artisan pos:list-tokens --expired

# Son N günü istifadə olunmayanlar
php artisan pos:list-tokens --unused-days=30
```

Plain-text token YOX, yalnız audit metadata: id, ad, abilities, son istifadə,
expires_at, status. Sızma riski yoxdur.

#### Revoke

```bash
# ID ilə (dəqiq)
php artisan pos:revoke-token --id=42 --reason="device lost"

# Merchant + ad ilə (rahat)
php artisan pos:revoke-token --merchant=m_412 --name=bravo-pos-01 --reason="rotation"

# Avtomatlaşma üçün təsdiq sorğusunu atla
php artisan pos:revoke-token --id=42 --force
```

Revoke dərhal effekt edir: növbəti API çağırışı **401 Unauthorized** qaytarır.
Audit event `api.pos.token.revoked` operator id, token id, səbəblə yazılır.

### 10.2 `POST /api/v1/pos/customer/lookup`

QR kodla müştərini tapır. Web `GET /pos/customer/{qr}`-dan fərqli olaraq POST
istifadə edilir — QR URL log-larında görünməsin.

**Request**
```json
{ "qr": "qr1.qr_clsztufrcaaa.1778998704.40801dc44cecc2cd" }
```

Həm rotating token (`qr1.{customer_qr}.{exp_unix}.{hmac16}`), həm static
`qr_xxx` qəbul edilir. Rotating üçün HMAC + expiry + replay yoxlanılır.

**Response 200 — uğurlu**
```json
{
  "status": "ok",
  "customer": { "id": 8, "name": "Aysel Hüseynova" },
  "bucket":   { "balance": 0, "earned_total": 0, "redeemed_total": 0 }
}
```

**Response 200 — tapılmadı** (enumeration qarşısı: status fərqli, struktur eyni)
```json
{ "status": "not_found", "customer": null, "bucket": null }
```

Customer `customer_qr`, `email`, `phone` cavabda **yoxdur** — POSNET sale axını
üçün yalnız `id` lazımdır.

### 10.3 `POST /api/v1/pos/sale/preview`

Satışı yazmadan preview hesablaması — kassir ekranında "ödəniləcək məbləğ" və
"qazanılacaq bonus" göstərmək üçün.

**Request**
```json
{
  "customer_id":       8,
  "sale_amount_cents": 5000,
  "use_bonus":         true,
  "redeem_cents":      100,
  "branch_id":         3
}
```

**Response 200**
```json
{
  "sale_amount":       5000,
  "earn_amount":       125,
  "redeem_amount":     100,
  "final_to_pay":      4900,
  "projected_balance": 25
}
```

Hesablama qaydaları (eyni `SaleAmountComputer` xidməti web POS controller-i ilə
paylaşılır — preview və complete iki səth arasında drift edə bilməz):

- `earn` — `EarnCalculator` formulu (kateqoriya × tier basis points).
- `redeem` — `min(bucket_balance, sale_amount, sale_amount × max_percent ÷ 100)`.
- `use_bonus=false` olduqda `redeem_cents` payload-da olmamalıdır (P-8).

### 10.4 `POST /api/v1/pos/sale`

Satışı tamamla — atomik şəkildə `Transaction` + `LedgerEntry` (earn və redeem)
yazılır.

**Headers**
```
Authorization: Bearer <token>
Idempotency-Key: <8–128 simvol, [A-Za-z0-9_-]>     (opsional, lakin tövsiyyə olunur)
Content-Type: application/json
Accept: application/json
```

**Request**
```json
{
  "customer_id":       8,
  "sale_amount_cents": 5000,
  "receipt_no":        "POS01-2026-06-04-00042",
  "branch_id":         3,
  "use_bonus":         false
}
```

`receipt_no` format: `^[A-Za-z0-9_\-]{1,64}$`. Boşluq, nöqtə, vergül qadağandır.

**Response 200 — yeni satış**
```json
{
  "transaction_id": 147,
  "receipt_no":     "POS01-2026-06-04-00042",
  "status":         "completed",
  "idempotent":     false
}
```

**Response 200 — idempotent retry**

Eyni `(merchant + receipt_no)` ikinci dəfə gəldikdə:
```json
{ "transaction_id": 147, "receipt_no": "POS01-2026-06-04-00042", "status": "completed", "idempotent": true }
```

Header `Idempotency-Key` istifadə olunarsa, eyni açar + eyni body üçün cavab
cache-dən qaytarılır və əlavə header `Idempotent-Replay: true` qoyulur.

**Response 422 — Idempotency-Key body conflict**

Eyni `Idempotency-Key` fərqli body ilə təkrar göndərilərsə:
```json
{
  "message": "Idempotency-Key əvvəlki sorğudan fərqli body ilə təkrar istifadə edildi.",
  "errors":  { "Idempotency-Key": ["Eyni açarın iki fərqli body ilə işlənməsinə icazə verilmir."] }
}
```

**Response 422 — InsufficientFunds**

`use_bonus=true` və balans `redeem_cents`-ə çatmırsa:
```json
{
  "status":          "insufficient_funds",
  "message":         "...",
  "available_cents": 50,
  "required_cents":  100
}
```

### 10.5 `GET /api/v1/pos/transactions` — Reconciliation feed

POSNET (və ya digər M2M client) öz lokal sale qeydlərini Paylo-nun yazılmış
vəziyyəti ilə təsdiq etmək üçün bu feed-i çəkir. Tipik istifadə ssenarisi:

> **Network failure self-heal**: POSNET `POST /pos/sale` çağırır, Paylo commit
> edir, lakin HTTP cavab POSNET-ə çatmır (timeout, paket itkisi). Idempotency-Key
> retry-i 24 saat ərzində uğurlu cavabı qaytarır, lakin TTL bitsə POSNET artıq
> əmin ola bilməz ki, satış Paylo-ya keçib. Periodic reconciliation feed bu boşluğu
> bağlayır: "son uğurlu sync-dan bəri hansı tx-lər mövcuddur?" sualına dəqiq cavab.

**Query parametrləri**

| Param | Type | Qeyd |
|---|---|---|
| `cursor` | string | Əvvəlki cavabdakı `next_cursor` |
| `since` | datetime | ISO-8601 / `YYYY-MM-DD` — `occurred_at >= since` |
| `until` | datetime | ISO-8601 / `YYYY-MM-DD` — `occurred_at <= until`, `since`-dən sonra olmalıdır |
| `status` | string | `completed` \| `reversed` \| `refunded` |
| `limit` | int | 1–200, default 50 |

**Response 200**

```json
{
  "data": [
    {
      "transaction_id":   148,
      "receipt_no":       "POS01-002",
      "branch_id":        null,
      "customer_id":      8,
      "cashier_id":       16,
      "sale_amount":      10000,
      "earned_amount":    250,
      "redeemed_amount":  50,
      "status":           "completed",
      "occurred_at":      "2026-06-04T02:59:50+04:00",
      "created_at":       "2026-06-04T02:59:50+04:00"
    }
  ],
  "next_cursor": "eyJpZCI6MTQ4LCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9",
  "has_more":    true
}
```

**Sıralama:** `occurred_at DESC, id DESC`. Yeni insert-lər cari pagination-ı
sındırmır (cursor `id`-yə bağlıdır).

**Təhlükəsizlik və qaydalar:**

- Merchant scope avtomatik — token sahibinin merchant-ından kənar tx-lər heç vaxt
  görünməz. Cross-merchant `since` ötürmək cəhdi heç vaxt başqa merchant-ın
  verisini açmır.
- Müştəri PII (email/phone/customer_qr) cavabda **yoxdur** — POSNET reconciliation
  fəaliyyəti üçün yalnız metadata lazımdır.
- Audit event: `api.pos.transactions.feed` (request parametrləri + qaytarılan count).

**curl nümunəsi:**

```bash
# Son 24 saatın tx-lərini çək
SINCE=$(date -u -d '24 hours ago' '+%Y-%m-%dT%H:%M:%S+04:00')
curl -G "http://localhost:8000/api/v1/pos/transactions" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode "since=$SINCE" \
  --data-urlencode "limit=200"

# Növbəti səhifə
curl -G "http://localhost:8000/api/v1/pos/transactions" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  --data-urlencode "cursor=<next_cursor-dən>"
```

**POSNET reconciliation pseudo-kod:**

```python
last_seen = state.get_last_reconciled_at()  # ISO-8601
cursor = None
while True:
    r = client.get('/api/v1/pos/transactions', params={
        'since': last_seen,
        'limit': 200,
        'cursor': cursor,
    })
    for tx in r['data']:
        local = local_db.find_by_receipt(tx['receipt_no'])
        if local is None:
            log.warning('paylo has tx we don't: %s', tx)  # POSNET state drift
        elif local.status != tx['status']:
            log.warning('status divergence: %s', tx['receipt_no'])
    if not r['has_more']:
        break
    cursor = r['next_cursor']
state.set_last_reconciled_at(now())
```

### 10.7 `POST /api/v1/pos/sale/{receipt_no}/reverse`

Satışı geri qaytar. Atomik şəkildə earn ledger entry-si rollback olunur və
(varsa) redeem-i bərpa edir. Müştəri bonus-u artıq xərcləyibsə (refund underflow
olar) — 422 + insan-oxunaqlı mesaj.

**Request**
```json
{ "return_receipt_no": "RET-2026-06-04-00007", "reason": "customer return" }
```

**Response 200 — uğurlu reverse**
```json
{
  "transaction_id":   147,
  "receipt_no":       "POS01-2026-06-04-00042",
  "status":           "reversed",
  "already_reversed": false,
  "reverse_entries": [
    { "uid": "le_01KT7VFQR4HS4K84H8VKJ5YY2E", "type": "reversal", "amount": 125 }
  ]
}
```

**Response 200 — idempotent (artıq reversed)**
```json
{ "transaction_id": 147, "receipt_no": "...", "status": "reversed", "already_reversed": true, "reverse_entries": [] }
```

**Response 404** — qəbz başqa merchant-a aiddir və ya yoxdur. Cavab strukturu
hər iki halda eynidir (enumeration qarşısı):
```json
{ "status": "not_found", "message": "Bu qəbz tapılmadı." }
```

### 10.8 Cross-cutting

**Throttle:** 120/dəq/token. Customer endpoint-lərindən ayrı bucket-də.
Header-lər (`X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`)
[bölmə 6](#throttling) ilə eyni qaydada qoyulur.

**Merchant scope override yoxdur.** Token-in sahibi olan user-in `merchant_id`-si
yeganə həqiqət mənbəyidir. Request body-də `merchant_id` göndərmək olar, lakin
təsir etməz. Branch_id və receipt_no axtarışları yalnız token-in merchant-ı
daxilində aparılır.

**Audit log eventləri** (AuditLogger vasitəsilə):

| Event | Tetikleyici |
|---|---|
| `api.pos.token.issued` | `php artisan pos:issue-token` çağırışı |
| `api.pos.customer.lookup` | Hər lookup (status=ok/not_found/error) |
| `api.pos.customer.lookup.mark_used_failed` | Rotating QR cache mark-used uğursuzluğu |
| `api.pos.sale.complete` | Hər complete (idempotent retry daxil) |
| `api.pos.sale.complete.idempotent_race` | Paralel sorğu yarışı |
| `api.pos.sale.reverse` | Hər reverse (already_reversed daxil) |
| `api.pos.transactions.feed` | Reconciliation feed çağırışı |
| `api.pos.token.revoked` | `php artisan pos:revoke-token` çağırışı (operator + token + reason) |

**curl nümunəsi (tam satış axını):**

```bash
TOKEN="5|pY1npE6IQ5pKHzEREbkBHclCsqJCr4xpv8o2Ilo2973453bc"
H_BASE="-H 'Authorization: Bearer $TOKEN' -H 'Accept: application/json' -H 'Content-Type: application/json'"

# 1. Lookup
curl -X POST http://localhost:8000/api/v1/pos/customer/lookup \
  $H_BASE -d '{"qr":"qr_clsztufrcaaa"}'

# 2. Preview (komplikasiyasız 50 AZN satış)
curl -X POST http://localhost:8000/api/v1/pos/sale/preview \
  $H_BASE -d '{"customer_id":8,"sale_amount_cents":5000,"use_bonus":false}'

# 3. Sale (idempotency-key ilə)
curl -X POST http://localhost:8000/api/v1/pos/sale \
  $H_BASE -H 'Idempotency-Key: 01HX7K3R4M5N6P7Q8R9S' \
  -d '{"customer_id":8,"sale_amount_cents":5000,"receipt_no":"POS01-001","use_bonus":false}'

# 4. Reverse
curl -X POST http://localhost:8000/api/v1/pos/sale/POS01-001/reverse \
  $H_BASE -d '{"return_receipt_no":"RET-001","reason":"customer changed mind"}'

# 5. Reconciliation feed (son 24 saat)
curl -G "http://localhost:8000/api/v1/pos/transactions" $H_BASE \
  --data-urlencode "since=2026-06-03T00:00:00+04:00" --data-urlencode "limit=200"
```

### 10.9 Outbound webhooks (V2 — Paylo → POSNET)

POSNET-in initiate etmədiyi hadisələri (admin reverse, bucket expire) POSNET-ə
xəbər vermək üçün Paylo HTTP webhook göndərir. Hər webhook HMAC-imzalanır
(POS API ilə eyni sxem).

#### Endpoint qeydiyyatı

```bash
php artisan pos:register-webhook \
  --merchant=m_412 \
  --name=posnet-prod \
  --url=https://posnet.example/loyalty-events \
  --events=admin_reverse,bucket_expire
```

HMAC secret avtomatik 32-byte hex generasiya olunur, **bir dəfə** çıxışda
göstərilir. Receiver tərəfdə Vault-da saxlayın
(`vault://secret/posnet/loyalty/<code>/webhook_secret`).

| Komanda | Funksiya |
|---|---|
| `pos:register-webhook` | Yeni endpoint + HMAC secret |
| `pos:list-webhooks` | Endpoint-lər + delivery statistikası |
| `pos:revoke-webhook --id=N [--delete]` | Deaktiv et və ya tam sil |

#### Event taxonomiyası

| Event type | Trigger | Payload əsas sahələr |
|---|---|---|
| `admin_reverse` | Web POS / Admin UI reverse (POSNET-in xaricindən) | `transaction_id`, `receipt_no`, `customer_id`, `return_receipt_no`, `reversed_at`, `source` |
| `bucket_expire` | Gecə `loyalty:expire-buckets` cron | `bucket_id`, `customer_id`, `amount_expired_cents`, `new_balance`, `threshold` |

> **Vacib:** API POS-dan gələn reverse `admin_reverse` event-i fire **etmir**
> — POSNET-in özü reverse-i initiate etdi və artıq tx-i bilir.

#### Request format

```http
POST /loyalty-events HTTP/1.1
Content-Type: application/json
X-Paylo-Event: admin_reverse
X-Paylo-Event-Id: 01HX7K3R4M5N6P7Q8R9SABCDEF      # ULID — receiver idempotency açarı
X-Paylo-Timestamp: 1780565270
X-Paylo-Signature: sha256=<64 hex>                 # HMAC-SHA256(ts + "." + body)
User-Agent: Paylo-Webhook/1.0

{
  "event_id": "01HX7K3R4M5N6P7Q8R9SABCDEF",
  "event_type": "admin_reverse",
  "occurred_at": "2026-06-04T13:00:00+04:00",
  "data": {
    "transaction_id": 147,
    "receipt_no": "R-001",
    "merchant_id": 1,
    "customer_id": 8,
    "return_receipt_no": "RET-001",
    "reason": "customer dispute",
    "reversed_at": "2026-06-04T13:00:00+04:00",
    "actor_id": 1,
    "source": "admin.transaction.reverse"
  }
}
```

#### Receiver tərəfində məcburi yoxlamalar

1. `X-Paylo-Timestamp` ±300 saniyə pəncərəsində olmalıdır (replay protection).
2. `X-Paylo-Signature = sha256=hmac_sha256(ts + "." + raw_body, secret)` —
   **constant-time** müqayisə (`hash_equals` / `hmac.compare_digest`).
3. `X-Paylo-Event-Id` POSNET-də idempotency açarı kimi saxlanmalıdır. Eyni event
   ID-li ikinci POST-u silent əmin uğurla qaytarın (200) — yeni iş görməyin.
4. Body `event_type` sahəsi `X-Paylo-Event` header-i ilə uyğun olmalıdır
   (mismatch = tampering signal, 400).

POSNET tərəfdə Python istifadə edirsinizsə [`libs/loyalty_client/webhooks.py`](../adapter/libs/loyalty_client/webhooks.py)
hazırdır — `WebhookVerifier` bütün yoxlamaları edir, qaytardığı tipli
`AdminReverseEvent` / `BucketExpireEvent` Pydantic v2 modelləridir.

#### Delivery semantics

- **Sync delivery** (v1): tetikleyici event-dən sonra ledger transaction
  commit olunduqdan sonra dərhal POST. Sale axınını sındırmır — webhook
  uğursuzluğu DB-də `webhook_deliveries` cədvəlində `failed` status-la qalır.
- **Manual retry** (v1.5 roadmap): `pos:webhook-redeliver --id=<delivery_id>`.
- **Auto-retry queue** (v2 roadmap): Laravel queue + exponential backoff.

#### Audit log eventləri

| Event | Tetikleyici |
|---|---|
| `api.pos.webhook.registered` | `pos:register-webhook` |
| `api.pos.webhook.deactivated` | `pos:revoke-webhook` (default soft) |
| `api.pos.webhook.deleted` | `pos:revoke-webhook --delete` |
| `api.pos.webhook.delivered` | Uğurlu 2xx cavab |
| `api.pos.webhook.failed` | 4xx/5xx və ya network exception |

### 10.10 İmplementasiya statusu (v1)

İcra olundu (v1 + V2 hardening):
- 5 inbound endpoint (lookup/preview/sale/reverse + transactions feed).
- İki ability — `pos:write` (sale) + `pos:reverse` (refund). Reverse default-da
  ayrı token tələb edir (audit P-4 ekvivalenti).
- `Idempotency-Key` header middleware (response replay, body hash validation,
  per-token namespace).
- Domain-level `(merchant_id, receipt_no)` unique constraint.
- Mağaza scope token sahibindən, payload override yox.
- Cursor-paginated reconciliation feed (`occurred_at` ilə sıralı, PII-siz).
- Token operations: `pos:issue-token`, `pos:list-tokens`, `pos:revoke-token`.
- **HMAC body signing** (`X-Paylo-Signature` + `X-Paylo-Timestamp`) — opt-in via `--require-hmac`.
- **Outbound webhooks** (Paylo → POSNET): `admin_reverse`, `bucket_expire`.
- Webhook operations: `pos:register-webhook`, `pos:list-webhooks`, `pos:revoke-webhook`.
- POSNET tərəfində Python client paketi (`adapter/libs/loyalty_client/`):
  client, Vault loader, reconciliation, auto-rotation, HMAC signing, webhook verifier.
- **316 Paylo Pest test + 73 Posnet Python test** (tam regression yaşıl).

Roadmap (V2.5+):
- Webhook auto-retry queue (currently sync + manual redeliver).
- `pos:webhook-redeliver --id=N` komandası.
- Webhook delivery dashboard (Inertia merchant panelində).
- mTLS / cert pinning (defense-in-depth-in V3-ü).
