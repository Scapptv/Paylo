<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\BonusAdjustmentController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\LedgerController;
use App\Modules\Admin\Http\Controllers\MerchantController;
use App\Modules\Admin\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/ledger',          [LedgerController::class, 'index'])->name('ledger');
        Route::get('/ledger/{entry}',  [LedgerController::class, 'show'])->name('ledger.show');

        // Merchant CRUD (Sprint 7.2) — order matters: `create` statik route
        // `show` model-bound-undan əvvəl qoyulur.
        Route::get('/merchants',                  [MerchantController::class, 'index'])->name('merchants');
        Route::get('/merchants/create',           [MerchantController::class, 'create'])->name('merchants.create');
        Route::post('/merchants',                 [MerchantController::class, 'store'])->name('merchants.store');
        Route::get('/merchants/{merchant}',       [MerchantController::class, 'show'])->name('merchants.show');
        Route::get('/merchants/{merchant}/edit',  [MerchantController::class, 'edit'])->name('merchants.edit');
        Route::put('/merchants/{merchant}',       [MerchantController::class, 'update'])->name('merchants.update');

        // Transactions — read + reverse (yalnız admin, məcburi `reason` audit üçün).
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions');
        Route::post('/transactions/{transaction}/reverse', [TransactionController::class, 'reverse'])
            ->name('transactions.reverse');

        // Manual bonus düzəlişi (Audit 2026-06-04 CANON-4) — CREDIT-only, məcburi
        // `reason` (audit). Çatışmayan reverse-dən sonra redeem-i geri qaytarmaq
        // və ya goodwill kredit üçün bərpa yolu.
        Route::post('/bonus-adjustments', [BonusAdjustmentController::class, 'store'])
            ->name('bonus-adjustments.store');
    });
