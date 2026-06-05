<?php

declare(strict_types=1);

use App\Modules\Merchant\Http\Controllers\DashboardController;
use App\Modules\Merchant\Http\Controllers\StaffController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:merchant_owner,merchant_staff', 'merchant.scope'])
    ->prefix('merchant')
    ->name('merchant.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    });

// Staff CRUD — yalnız MerchantOwner. Sprint 7.3: cashier/staff özü-özünü yaratmasın
// deyə (privilege escalation) ayrıca `role:merchant_owner` middleware ilə qorunur.
Route::middleware(['auth', 'role:merchant_owner', 'merchant.scope'])
    ->prefix('merchant/staff')
    ->name('merchant.')
    ->group(function () {
        Route::get('/',              [StaffController::class, 'index'])->name('staff');
        Route::get('/create',        [StaffController::class, 'create'])->name('staff.create');
        Route::post('/',             [StaffController::class, 'store'])->name('staff.store');
        Route::get('/{staff}/edit',  [StaffController::class, 'edit'])->name('staff.edit');
        Route::put('/{staff}',       [StaffController::class, 'update'])->name('staff.update');
        Route::delete('/{staff}',    [StaffController::class, 'destroy'])->name('staff.destroy');
    });
