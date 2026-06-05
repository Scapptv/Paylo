<?php

declare(strict_types=1);

use App\Modules\Pos\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:cashier,pos_terminal,merchant_owner,merchant_staff', 'merchant.scope'])
    ->prefix('pos')
    ->name('pos.')
    ->group(function () {
        Route::get('/sale', [SaleController::class, 'show'])->name('sale');

        // QR lookup endpoint-i throttle ilə qorunur — QR enumeration hücumlarına qarşı.
        // 30 sorğu / dəqiqə hər bir authenticated kassir üçün kifayət edir.
        Route::middleware('throttle:30,1')
            ->get('/customer/{qr}', [SaleController::class, 'lookupCustomer'])
            ->name('lookup');

        Route::post('/preview', [SaleController::class, 'preview'])->name('preview');
        Route::post('/sale', [SaleController::class, 'complete'])->name('complete');
    });

// Reverse — yalnız merchant sahibi/işçisi və admin. Kassir/POS terminalı satışı geri qaytara bilməz
// (audit P-4: kassir səviyyəsində refund vəzifə bölgüsünü pozur, fraud riski yaradır).
Route::middleware(['auth', 'role:merchant_owner,merchant_staff,admin', 'merchant.scope'])
    ->prefix('pos')
    ->name('pos.')
    ->group(function () {
        // Satışı geri qaytar — receipt_no path-də, merchant scope-dan kənar qəbz 404 verir.
        Route::post('/sale/{receiptNo}/reverse', [SaleController::class, 'reverse'])
            ->where('receiptNo', '[A-Za-z0-9._-]{1,64}')
            ->name('reverse');
    });
