<?php

declare(strict_types=1);

namespace App\Modules\Api\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Flutter mobile app üçün REST API qatının service provider-i.
 *
 * Mövcud Inertia/Web stack-ə toxunmur — yalnız `/api/v1/...` prefix-i altında
 * stateless, Sanctum bearer-token rejimində ayrı route group yükləyir.
 */
final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $routesPath = __DIR__ . '/../Routes/api.php';

        if (! file_exists($routesPath)) {
            return;
        }

        Route::middleware('api')
            ->prefix('api')
            ->group($routesPath);
    }
}
