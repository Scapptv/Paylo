<?php

declare(strict_types=1);

use App\Modules\User\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:customer'])
    ->group(function () {
        Route::get('/wallet', [WalletController::class, 'show'])->name('user.wallet');
    });
