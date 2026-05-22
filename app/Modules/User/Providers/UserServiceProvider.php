<?php

declare(strict_types=1);

namespace App\Modules\User\Providers;

use App\Core\Support\ModuleServiceProvider;

class UserServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'User';
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/../Routes/web.php';
    }
}
