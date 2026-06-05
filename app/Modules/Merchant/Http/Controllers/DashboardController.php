<?php

declare(strict_types=1);

namespace App\Modules\Merchant\Http\Controllers;

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Transaction;
use App\Core\Support\PiiMasker;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merchant paneli: yalnız bu merchant-a aid məlumatlar.
 * `EnsureMerchantScope` middleware request-i merchant_id ilə bağlamış olur.
 */
class DashboardController extends Controller
{
    /**
     * Audit M-5: per-merchant aggregate cache (5 dəq). Cache key tarixi
     * günlük olaraq fərqlənir ki, 30-günlük rolling window keçici sınır
     * effektləri olmadan yenilənsin.
     */
    private const STATS_TTL_SECONDS = 300;

    public function index(Request $request): Response
    {
        // Audit M-6 (by design): ikili scope check — `EnsureMerchantScope`
        // middleware route qruplarında zaten `merchant_id`-ni request attributes-ə
        // qoyur. Burada təkrar yoxlama sürətli regression detektorudur — əgər
        // gələcəkdə kimsə endpoint-i middleware-siz qrupa daşısa, 500 alır
        // (silent boş data əvəzinə). Defense-in-depth, performance cəzası yoxdur.
        $merchantId = $request->attributes->get('merchant_id');
        if (! is_int($merchantId) || $merchantId <= 0) {
            abort(500, 'Merchant scope middleware aktiv deyil — bu endpoint merchant-scoped olmalıdır.');
        }

        $since   = now()->subDays(30);
        $dayKey  = now()->toDateString();
        $cacheNs = "merchant.{$merchantId}.dashboard.{$dayKey}";

        $stats = Cache::remember("{$cacheNs}.stats", self::STATS_TTL_SECONDS, fn () => [
            'customers'       => Bucket::where('merchant_id', $merchantId)->count(),
            'totalLocked'     => (int) Bucket::where('merchant_id', $merchantId)->sum('balance'),
            // Audit M-2: magic string əvəzinə LedgerEntryType enum.
            'earned30d'       => (int) LedgerEntry::where('merchant_id', $merchantId)
                ->where('type', LedgerEntryType::Earn)
                ->where('created_at', '>=', $since)
                ->sum('amount'),
            'redeemed30d'     => (int) LedgerEntry::where('merchant_id', $merchantId)
                ->where('type', LedgerEntryType::Redeem)
                ->where('created_at', '>=', $since)
                ->sum('amount'),
            'transactions30d' => Transaction::where('merchant_id', $merchantId)
                ->where('occurred_at', '>=', $since)
                ->count(),
        ]);

        // Audit M-4: PII masking — yalnız MerchantOwner tam telefon nömrəsini görür.
        // Staff üçün telefon middle-mask olunur (`+994*****567`).
        //
        // Audit 2026-06-04 WEB-1 (PII cache leak fix): maskalama CACHE-DƏN KƏNARDA,
        // hər sorğuda viewer roluna görə tətbiq olunur. Əvvəllər maska
        // `Cache::remember` daxilində baked olunurdu və cache açarı rola görə
        // ayrılmadığı üçün owner cache-i "isti" edəndə (maskasız) həmin TTL ərzində
        // merchant_staff tam telefon nömrələrini görürdü. İndi cache yalnız RAW
        // (maskasız) data saxlayır — bu server-side trusted store-dur; maska
        // client-ə getməzdən əvvəl, hər istifadəçi üçün ayrıca tətbiq olunur.
        $rawTopCustomers = Cache::remember(
            "{$cacheNs}.top_customers",
            self::STATS_TTL_SECONDS,
            fn () => Bucket::with('user:id,name,phone')
                ->where('merchant_id', $merchantId)
                ->orderByDesc('earned_total')
                ->limit(10)
                ->get()
                ->map(fn (Bucket $b) => $b->toArray())
                ->all(),
        );

        $isOwner      = $request->user()?->role === UserRole::MerchantOwner;
        $topCustomers = array_map(function (array $row) use ($isOwner) {
            if (! $isOwner && isset($row['user']['phone'])) {
                $row['user']['phone'] = PiiMasker::phone((string) $row['user']['phone']);
            }

            return $row;
        }, $rawTopCustomers);

        return Inertia::render('Merchant/Dashboard', [
            'stats'              => $stats,
            // `recentTransactions` cache-lənmir — operator son satışları canlı görür.
            'recentTransactions' => Transaction::with(['customer:id,name', 'cashier:id,name', 'branch:id,name'])
                ->where('merchant_id', $merchantId)
                ->latest('occurred_at')
                ->limit(15)
                ->get(),
            'topCustomers' => $topCustomers,
        ]);
    }

}
