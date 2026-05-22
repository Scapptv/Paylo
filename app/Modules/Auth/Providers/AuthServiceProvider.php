<?php

declare(strict_types=1);

namespace App\Modules\Auth\Providers;

use App\Core\Support\ModuleServiceProvider;

class AuthServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Auth';
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/../Routes/web.php';
    }
}
