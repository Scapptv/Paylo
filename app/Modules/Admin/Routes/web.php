<?php

declare(strict_types=1);

use App\Modules\Admin\Http\Controllers\BonusAdjustmentController;
use App\Modules\Admin\Http\Controllers\BucketController;
use App\Modules\Admin\Http\Controllers\DashboardController;
use App\Modules\Admin\Http\Controllers\LedgerController;
use App\Modules\Admin\Http\Controllers\MerchantController;
use App\Modules\Admin\Http\Controllers\SettlementController;
use App\Modules\Admin\Http\Controllers\TransactionController;
use App\Modules\Admin\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/ledger',          [LedgerController::class, 'index'])->name('ledger');
        Route::get('/ledger/{entry}',  [LedgerController::class, 'show'])->name('ledger.show');

        // Roadmap Phase 2.1: per-merchant bucket read-view (balans + counter-lər).
        Route::get('/buckets', [BucketController::class, 'index'])->name('buckets');

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

        // Roadmap Phase 2.2: istifadəçi idarəetməsi — siyahı + filter + aktivlik toggle.
        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])
            ->name('users.toggle-active');

        // Roadmap Phase 2.4: settlement reconciliation read-view + "İndi işlət" (audit).
        Route::get('/settlements',     [SettlementController::class, 'index'])->name('settlements');
        Route::post('/settlements/run', [SettlementController::class, 'run'])->name('settlements.run');

        // Manual bonus düzəlişi (Audit 2026-06-04 CANON-4 + roadmap Phase 1.1) —
        // CREDIT-only, məcburi `reason` (audit). create() admin UI forması,
        // store() həm Inertia (email), həm API (customer_id) qəbul edir.
        Route::get('/bonus-adjustments',  [BonusAdjustmentController::class, 'create'])->name('bonus-adjustments.create');
        Route::post('/bonus-adjustments', [BonusAdjustmentController::class, 'store'])->name('bonus-adjustments.store');
    });
