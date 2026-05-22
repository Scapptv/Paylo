<?php

declare(strict_types=1);

namespace App\Modules\Merchant\Providers;

use App\Core\Support\ModuleServiceProvider;

class MerchantServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Merchant';
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/../Routes/web.php';
    }
}
