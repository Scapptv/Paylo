<?php

use App\Http\Middleware\EnsureMerchantScope;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
        ]);

        // Sanctum SPA cookie-əsaslı autentifikasiya üçün stateful middleware
        // (mobile token istifadəsinə təsir etmir). Sonra `EnsureUserIsActive`
        // ilə deaktiv hesabların API-yə də çıxışını blokla.
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            EnsureUserIsActive::class,
        ]);

        $middleware->alias([
            'role'           => EnsureRole::class,
            'merchant.scope' => EnsureMerchantScope::class,
            'active'         => EnsureUserIsActive::class,
            'ability'        => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);
    })
    ->withProviders([
        // Modul service provider-ləri — burada qeydiyyatdan keçir
        App\Modules\Auth\Providers\AuthServiceProvider::class,
        App\Modules\Admin\Providers\AdminServiceProvider::class,
        App\Modules\Merchant\Providers\MerchantServiceProvider::class,
        App\Modules\Cashier\Providers\CashierServiceProvider::class,
        App\Modules\Pos\Providers\PosServiceProvider::class,
        App\Modules\User\Providers\UserServiceProvider::class,
        App\Modules\Api\Providers\ApiServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions) {
        // API və ya JSON gözləyən sorğular üçün exception-lar HTML deyil JSON qaytarsın.
        $exceptions->shouldRenderJsonWhen(function ($request, $throwable) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
