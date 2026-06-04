<?php

use App\Core\Exceptions\InsufficientFundsException;
use App\Http\Middleware\AddRateLimitHeaders;
use App\Http\Middleware\EnsureMerchantScope;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Production: Nginx reverse proxy arxasında HTTPS termination olur.
        // Laravel-ə "X-Forwarded-Proto: https" header-ə güvənməyi öyrədirik
        // ki, URL::current() və route(), https scheme generasiya etsin.
        // `TRUSTED_PROXIES` .env-də konkret IP siyahısı təyin etmək tövsiyə
        // olunur (məs `127.0.0.1,10.0.0.0/8`); `*` development üçündür.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );

        // Audit H-5: pipeline sırası önəmlidir.
        //   `web` group: HandleInertiaRequests → AddLinkHeaders → EnsureUserIsActive.
        //   - HandleInertiaRequests Inertia-nın session/CSRF state-i set etməsindən
        //     sonra fire olunur (default web stack-da SessionStart-dan keçib).
        //   - EnsureUserIsActive AXIRDA gəlir ki, authenticated user kontekstdə olsun
        //     (auth() müəyyən, sonra is_active yoxlanılır). Auth middleware-dən
        //     əvvəl gələsi olsaydı, `Auth::user()` null verərdi.
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            EnsureUserIsActive::class,
        ]);

        // Sanctum SPA cookie-əsaslı autentifikasiya üçün stateful middleware
        // (mobile token istifadəsinə təsir etmir). Sonra `EnsureUserIsActive`
        // ilə deaktiv hesabların API-yə də çıxışını blokla.
        //
        // Sıra: PREPEND EnsureFrontendRequestsAreStateful → ... → APPEND
        // EnsureUserIsActive (Sanctum auth resolve etdikdən sonra).
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            EnsureUserIsActive::class,
            // Sprint 8 T-1: mobile client header-əsaslı throttle məlumatı alsın.
            // Default 60/dəq limit — Laravel-in `throttle:api` ilə eyni dəyər.
            AddRateLimitHeaders::class,
        ]);

        // Route-larda qısa ad ilə istifadə üçün alias-lar:
        //  `->middleware('role:admin')`               — EnsureRole
        //  `->middleware('merchant.scope')`           — EnsureMerchantScope (scope set edir)
        //  `->middleware('active')`                   — EnsureUserIsActive (təklikdə)
        //  `->middleware('ability:customer')`         — Sanctum CheckAbilities
        $middleware->alias([
            'role'           => EnsureRole::class,
            'merchant.scope' => EnsureMerchantScope::class,
            'active'         => EnsureUserIsActive::class,
            'ability'        => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'idempotent'     => \App\Modules\Api\Http\Middleware\IdempotencyKey::class,
            'verify.hmac'    => \App\Modules\Api\Http\Middleware\VerifyHmacSignature::class,
        ]);
    })
    ->withProviders([
        // Tətbiq səviyyəsində global ayarlar (HTTPS, Eloquent strict mode)
        App\Providers\AppServiceProvider::class,
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

        // Production error tracking — Sentry. `SENTRY_LARAVEL_DSN` boş olduqda
        // (məs lokal/test mühit) SDK no-op rejimində qalır, exception silent
        // şəkildə audit log + storage/logs/laravel.log-a düşür. Production-da
        // DSN .env-də qoyulur.
        $exceptions->reportable(function (\Throwable $e) {
            if (app()->bound('sentry')) {
                \Sentry\Laravel\Integration::captureUnhandledException($e);
            }
        });

        // Audit C-6: domain-specific InsufficientFundsException → 422 + struktur
        // mesaj. Generic RuntimeException kimi 500-ə düşməsin; HTTP layer-də
        // controller try/catch təkrarına ehtiyac qalmasın.
        $exceptions->render(function (InsufficientFundsException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // web context — default renderer-ə ötür
            }

            return response()->json([
                'status'   => 'insufficient_funds',
                'message'  => $e->getMessage(),
                'available_cents' => $e->available->amount,
                'required_cents'  => $e->required->amount,
            ], 422);
        });
    })->create();
