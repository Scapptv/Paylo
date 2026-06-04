<?php

declare(strict_types=1);

namespace App\Modules\Api\Providers;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Api\Console\Commands\IssuePosTokenCommand;
use App\Modules\Api\Console\Commands\ListPosTokensCommand;
use App\Modules\Api\Console\Commands\ListWebhooksCommand;
use App\Modules\Api\Console\Commands\RegisterWebhookCommand;
use App\Modules\Api\Console\Commands\RevokePosTokenCommand;
use App\Modules\Api\Console\Commands\RevokeWebhookCommand;

/**
 * Flutter mobile app + POSNET M2M inteqrasiyası üçün REST API qatının service
 * provider-i.
 *
 * Mövcud Inertia/Web stack-ə toxunmur — yalnız `/api/v1/...` prefix-i altında
 * stateless, Sanctum bearer-token rejimində ayrı route group yükləyir.
 *
 * Audit Api-17: digər modul provider-ləri kimi `ModuleServiceProvider`-i
 * extend edir; route group `routeMiddleware/routePrefix` hook-ları vasitəsilə
 * konfiqurasiya olunur.
 */
final class ApiServiceProvider extends ModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Api';
    }

    protected function routesPath(): string
    {
        return __DIR__ . '/../Routes/api.php';
    }

    protected function routeMiddleware(): string
    {
        return 'api';
    }

    protected function routePrefix(): ?string
    {
        return 'api';
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                IssuePosTokenCommand::class,
                ListPosTokensCommand::class,
                RevokePosTokenCommand::class,
                RegisterWebhookCommand::class,
                ListWebhooksCommand::class,
                RevokeWebhookCommand::class,
            ]);
        }
    }
}
