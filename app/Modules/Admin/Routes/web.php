<?php

declare(strict_types=1);

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

        Route::get('/merchants',          [MerchantController::class, 'index'])->name('merchants');
        Route::get('/merchants/{merchant}', [MerchantController::class, 'show'])->name('merchants.show');

        // Transactions — read + reverse (yalnız admin, məcburi `reason` audit üçün).
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions');
        Route::post('/transactions/{transaction}/reverse', [TransactionController::class, 'reverse'])
            ->name('transactions.reverse');
    });
