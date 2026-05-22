<?php

declare(strict_types=1);

namespace App\Modules\Admin\Providers;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Admin\Console\Commands\ExpireBucketsCommand;
use App\Modules\Admin\Console\Commands\SettlementReconcileCommand;

class AdminServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Admin';
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/../Routes/web.php';
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireBucketsCommand::class,
                SettlementReconcileCommand::class,
            ]);
        }
    }
}
