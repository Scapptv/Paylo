<?php

declare(strict_types=1);

use App\Modules\Api\Http\Controllers\V1\HistoryController;
use App\Modules\Api\Http\Controllers\V1\LoginController;
use App\Modules\Api\Http\Controllers\V1\Pos\PosSaleController;
use App\Modules\Api\Http\Controllers\V1\ProfileController;
use App\Modules\Api\Http\Controllers\V1\PushTokenController;
use App\Modules\Api\Http\Controllers\V1\QrController;
use App\Modules\Api\Http\Controllers\V1\RegisterController;
use App\Modules\Api\Http\Controllers\V1\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API — V1
|--------------------------------------------------------------------------
|
| Bu route-lar `ApiServiceProvider` tərəfindən `api` middleware qrupu və
| `/api` prefix-i ilə yüklənir. Burada əlavə olaraq `v1` prefix verilir.
|
| Bütün autentifikasiya `auth:sanctum` üzərindən aparılır. Mövcud session-
| based web auth-a heç bir təsir yoxdur.
|
*/

Route::prefix('v1')->group(function () {

    // --- Açıq endpoint-lər (throttle 5/dəq, brute-force qarşısı) ---
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/auth/login',    [LoginController::class, 'store']);
        Route::post('/auth/register', [RegisterController::class, 'store']);
    });

    // --- Autentifikasiya tələb olunan endpoint-lər (throttle 60/dəq) ---
    Route::middleware(['auth:sanctum', 'ability:customer', 'throttle:60,1'])->group(function () {

        // Auth lifecycle
        Route::post('/auth/logout',     [LoginController::class, 'destroy']);
        Route::post('/auth/logout-all', [LoginController::class, 'destroyAll']);

        // Profile
        Route::get('/me',          [ProfileController::class, 'show']);
        Route::put('/me',          [ProfileController::class, 'update']);
        Route::put('/me/password', [ProfileController::class, 'changePassword']);
        Route::delete('/me',       [ProfileController::class, 'destroy']);

        // Wallet & history
        Route::get('/wallet',                   [WalletController::class, 'show']);
        Route::get('/history',                  [HistoryController::class, 'index']);
        Route::get('/buckets/{bucket}/history', [HistoryController::class, 'forBucket']);

        // Push notification cihaz idarəsi
        Route::post('/push/register',   [PushTokenController::class, 'register']);
        Route::delete('/push/register', [PushTokenController::class, 'destroy']);
    });

    // --- Rotating QR endpoint-i ayrıca throttle 10/dəq ---
    Route::middleware(['auth:sanctum', 'ability:customer', 'throttle:10,1'])->group(function () {
        Route::get('/qr', [QrController::class, 'generate']);
    });

    /*
    |--------------------------------------------------------------------------
    | POS Integration API — M2M (POSNET ↔ Paylo)
    |--------------------------------------------------------------------------
    |
    | Bu route-lar kassir Inertia panelindən və mobile customer API-dən tam
    | izolyasiyalıdır. Hədəf: POSNET (Python/FastAPI ayrı host) Paylo-ya
    | loyallıq əməliyyatlarını machine-to-machine icra edə bilsin.
    |
    | - Auth: Sanctum bearer + ability `pos:write` (customer ability-dən ayrı).
    | - Merchant scope: token sahibinin merchant_id-sindən. Payload override-i
    |   yoxdur.
    | - Throttle: 120/dəq/token — kassir bot trafiki yüksək olur.
    | - Idempotency: `Idempotency-Key` header (cache replay) + domain-level
    |   (merchant_id, receipt_no) unique constraint.
    | - Token issuance: `php artisan pos:issue-token` komandası.
    |
    */
    Route::middleware(['auth:sanctum', 'ability:pos:write', 'throttle:120,1'])
        ->prefix('pos')
        ->group(function () {
            Route::post('/customer/lookup', [PosSaleController::class, 'lookupCustomer']);
            Route::post('/sale/preview',    [PosSaleController::class, 'preview']);

            // Reconciliation feed — POSNET self-heal üçün cursor-paginated tx siyahısı.
            Route::get('/transactions', [PosSaleController::class, 'transactions']);

            // `complete` üçün Idempotency-Key + HMAC body signing (token-də secret varsa)
            Route::middleware(['verify.hmac', 'idempotent'])
                ->post('/sale', [PosSaleController::class, 'complete']);
        });

    /*
    | Audit P-4 ekvivalenti API tərəfdə: reverse ƏLAVƏ ability `pos:reverse`
    | tələb edir. Web POS-da bunu yalnız merchant_owner/staff/admin edə bilirdi
    | (kassir səviyyəsində refund vəzifə bölgüsünü pozur). API-də sızdırılmış
    | satış token-i ilə müştəri bonusunun batch drenajının qarşısını alır:
    | reverse token-i yalnız operator istəyəndə (`--include-reverse`) verilir.
    */
    Route::middleware(['auth:sanctum', 'ability:pos:reverse', 'throttle:120,1', 'verify.hmac', 'idempotent'])
        ->prefix('pos')
        ->group(function () {
            Route::post('/sale/{receiptNo}/reverse', [PosSaleController::class, 'reverse'])
                ->where('receiptNo', '[A-Za-z0-9._-]{1,64}');
        });
});
