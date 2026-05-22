<?php

declare(strict_types=1);

use App\Modules\Cashier\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:cashier', 'merchant.scope'])
    ->prefix('cashier')
    ->name('cashier.')
    ->group(function () {
        Route::get('/shift', [ShiftController::class, 'index'])->name('shift');
    });
