<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Tətbiq səviyyəsində global ayarlar — production hardening.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Production checklist: HTTPS forced. APP_URL https:// ilə başladıqda
        // bütün generasiya olunan URL-lər (Inertia, route(), redirect()) https
        // scheme ilə qurulur. Bu, mövcud config-i təkrarlamır — sadəcə fallback
        // kimi APP_URL https-dirsə force edir.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
            URL::forceRootUrl((string) config('app.url'));
        }

        // Production-da Eloquent strict mode — N+1 detection, lazy loading
        // qadağası (defense yox, fail-fast development feedback). Lokal mühitdə
        // testlər üçün açıq; production-da log-only.
        Model::shouldBeStrict(! app()->isProduction());
    }
}
