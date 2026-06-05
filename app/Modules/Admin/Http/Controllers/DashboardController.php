<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\MerchantStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Audit Adm-2: dashboard count/sum sorğuları tək request-də 7+ aggregate
     * vurur. Belə hesablanmalar dəqiqəlik dəqiqliklə kifayət edir; 5 dəqiqəlik
     * `Cache::remember` admin-in tez-tez yenilədiyi paneldə DB yükünü 10x-ə qədər
     * azaldır. Cache key versionsuzdur — kritik dəyişiklik halında manual
     * `php artisan cache:clear` kifayətdir; gələcəkdə LedgerEntry observer-i
     * bu key-i invalidate edə bilər.
     */
    private const STATS_TTL_SECONDS = 300;

    public function index(): Response
    {
        // Audit P-5, M-2: magic string-lər əvəzinə enum.
        $stats = Cache::remember('admin.dashboard.stats', self::STATS_TTL_SECONDS, fn () => [
            'totalUsers'       => User::where('role', UserRole::Customer)->count(),
            'totalMerchants'   => Merchant::where('status', MerchantStatus::Active)->count(),
            'pendingMerchants' => Merchant::where('status', MerchantStatus::Pending)->count(),
            'totalBuckets'     => Bucket::count(),
            'totalLedger'      => LedgerEntry::count(),
            'totalLocked'      => (int) Bucket::sum('balance'),
            'last24hEntries'   => LedgerEntry::where('created_at', '>=', now()->subDay())->count(),
        ]);

        $topMerchants = Cache::remember(
            'admin.dashboard.top_merchants',
            self::STATS_TTL_SECONDS,
            fn () => Merchant::withCount('ledgerEntries')
                ->orderByDesc('ledger_entries_count')
                ->limit(5)
                ->get(['id', 'code', 'name', 'category', 'tier', 'status'])
                ->toArray(),
        );

        return Inertia::render('Admin/Dashboard', [
            'stats'        => $stats,
            // `recentEntries` cache-lənmir — admin son əməliyyatları canlı görür.
            'recentEntries' => LedgerEntry::with(['user:id,name', 'merchant:id,name,code'])
                ->latest()
                ->limit(20)
                ->get(['id', 'uid', 'user_id', 'merchant_id', 'type', 'amount', 'balance_after', 'created_at']),
            'topMerchants' => $topMerchants,
        ]);
    }
}
