<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bütün modul service provider-ləri üçün baza sinif.
 *
 * Subklas yalnız 4 abstract metod implement edir:
 *   - moduleName()  : "Admin", "Merchant" ...
 *   - routesPath()  : __DIR__ . '/../Routes/web.php'
 *
 * Avtomatik:
 *   - route-ları "web" middleware ilə yükləyir
 *   - module-spesifik translation-ları yükləyir (gələcəkdə)
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutes();
    }

    abstract protected function moduleName(): string;
    abstract protected function routesPath(): string;

    private function loadRoutes(): void
    {
        if (! file_exists($this->routesPath())) {
            return;
        }

        Route::middleware('web')->group($this->routesPath());
    }
}
