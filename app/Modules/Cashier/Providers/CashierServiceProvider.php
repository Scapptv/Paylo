<?php

declare(strict_types=1);

namespace App\Modules\Cashier\Providers;

use App\Core\Support\ModuleServiceProvider;

class CashierServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Cashier';
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/../Routes/web.php';
    }
}
