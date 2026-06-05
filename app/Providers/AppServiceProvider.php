<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Services\LoyaltyRuleResolver;
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

        // Roadmap Phase 4.2: DB-əsaslı loyalty qaydalarını (earn rate, tier
        // multiplier, redemption, expiry) config-ə tətbiq et. EarnCalculator
        // dəyişməz qalır — config oxuyur, config indi DB override-larını əks
        // etdirir. Defensiv: cədvəl yox / xəta → config faylı default-u qalır.
        $this->app->make(LoyaltyRuleResolver::class)->applyOverrides();
    }
}
