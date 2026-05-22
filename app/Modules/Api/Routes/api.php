<?php

declare(strict_types=1);

use App\Modules\Api\Http\Controllers\V1\HistoryController;
use App\Modules\Api\Http\Controllers\V1\LoginController;
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
});
