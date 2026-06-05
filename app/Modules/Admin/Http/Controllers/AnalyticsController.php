<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Services\AnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analytics — admin paneli dərin dashboard (roadmap Phase 4.1).
 *
 * Bütün metriklər `AnalyticsService`-də immutable ledger-dən KANONİK hesablanır
 * (Σcredits−Σdebits, integer qəpik). Controller yalnız `days` pəncərəsini
 * doğrulayır və nəticəni cache-ləyir (DashboardController ilə eyni 5-dəqiqəlik
 * pattern — aggregate-lər ağırdır, admin tez-tez yeniləyir).
 */
class AnalyticsController extends Controller
{
    /** İcazəli pəncərələr (gün). */
    private const ALLOWED_DAYS = [7, 30, 90];

    private const TTL_SECONDS = 300;

    public function index(Request $request, AnalyticsService $analytics): Response
    {
        $days = (int) $request->input('days', 30);
        if (! in_array($days, self::ALLOWED_DAYS, true)) {
            $days = 30;
        }

        $data = Cache::remember(
            "admin.analytics.v1.{$days}",
            self::TTL_SECONDS,
            fn () => $analytics->overview($days),
        );

        return Inertia::render('Admin/Analytics', [
            'analytics' => $data,
            'filters'   => ['days' => $days],
            'options'   => self::ALLOWED_DAYS,
        ]);
    }
}
