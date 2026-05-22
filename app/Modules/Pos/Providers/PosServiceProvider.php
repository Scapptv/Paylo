<?php

declare(strict_types=1);

namespace App\Modules\Pos\Providers;

use App\Core\Support\ModuleServiceProvider;

class PosServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Pos';
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/../Routes/web.php';
    }
}
