<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Bütün modul service provider-ləri üçün baza sinif.
 *
 * Subklas yalnız 2 abstract metod implement edir:
 *   - moduleName()  : "Admin", "Merchant" ...
 *   - routesPath()  : __DIR__ . '/../Routes/web.php'
 *
 * İstəyə görə override:
 *   - routeMiddleware() — default 'web'; Api modulu üçün 'api'.
 *   - routePrefix()     — default null; Api modulu üçün 'api'.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutes();
    }

    abstract protected function moduleName(): string;
    abstract protected function routesPath(): string;

    /**
     * Route group middleware. Default 'web' — Inertia + session.
     * Api üçün 'api' override edilir (Sanctum stateless).
     */
    protected function routeMiddleware(): string
    {
        return 'web';
    }

    /**
     * URI prefix-i (məs `api`). null isə prefix əlavə olunmur.
     */
    protected function routePrefix(): ?string
    {
        return null;
    }

    private function loadRoutes(): void
    {
        if (! file_exists($this->routesPath())) {
            return;
        }

        $route = Route::middleware($this->routeMiddleware());
        if ($this->routePrefix() !== null) {
            $route = $route->prefix($this->routePrefix());
        }
        $route->group($this->routesPath());
    }
}
