<?php

declare(strict_types=1);

use App\Modules\Auth\Services\RoleRouter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Root — login-ə ya da rola uyğun panelə yönləndir
Route::get('/', function (RoleRouter $router) {
    if (Auth::check()) {
        return redirect()->to($router->homeUrlFor(Auth::user()));
    }

    return redirect()->route('login');
})->name('home');
