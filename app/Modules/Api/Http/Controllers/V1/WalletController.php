<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Models\LedgerEntry;
use App\Http\Controllers\Controller;
use App\Modules\Api\Http\Resources\V1\BucketResource;
use App\Modules\Api\Http\Resources\V1\LedgerEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/wallet — müştərinin balans xülasəsi.
 *
 * Web tərəfdəki `App\Modules\User\Http\Controllers\WalletController`-in API ekvivalentidir,
 * lakin Inertia əvəzinə JSON qaytarır və mobile tələblərinə uyğun aggregate sahələr
 * (expiring_soon, *_all_time) əlavə edir.
 */
final class WalletController extends Controller
{
    /**
     * Bonus 1 il (365 gün) ərzində aktivlik olmadıqda silinir. Mobile UI son
     * 30 gün üçün "tezliklə bitir" rozeti göstərsin deyə eşiyi 335 gün
     * əvvəlki tarix ilə hesablayırıq (365 - 30).
     *
     * MVP qeydi: faktiki silmə ExpireBucketsCommand tamamlananda işə düşəcək —
     * indi yalnız UI üzərində "tezliklə bitir" rozeti üçün məbləğ hesablanır.
     */
    private const EXPIRING_SOON_THRESHOLD_DAYS = 335;

    /** Audit Usr-1 / Api-9: bucket sayı çox olan müştərilər üçün ilk səhifə limiti. */
    private const BUCKETS_PER_PAGE = 30;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Aggregate-lər BÜTÜN bucket-lər üzərində hesablanır — pagination
        // wallet-in cəm dəyərini təhrif etməsin.
        $aggregates = $user->buckets()
            ->selectRaw('COUNT(*) AS bucket_count,
                         COALESCE(SUM(balance), 0)        AS total_balance,
                         COALESCE(SUM(earned_total), 0)   AS total_earned,
                         COALESCE(SUM(redeemed_total), 0) AS total_redeemed')
            ->first();

        $expiringThreshold = Carbon::now()->subDays(self::EXPIRING_SOON_THRESHOLD_DAYS);
        $expiringSoon = (int) $user->buckets()
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<', $expiringThreshold)
            ->sum('balance');

        // Audit Usr-1 / Api-9: ilk N bucket cursor-paginated. Mobile `next_cursor`
        // ilə "Daha çox göstər" düyməsi üçün davam edə bilər.
        $bucketsPage = $user->buckets()
            ->with('merchant:id,code,name,category,tier')
            ->orderByDesc('balance')
            ->cursorPaginate(self::BUCKETS_PER_PAGE);

        $recentEntries = LedgerEntry::with('merchant:id,code,name,category')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'total_balance'           => (int) $aggregates->total_balance,
            'total_earned_all_time'   => (int) $aggregates->total_earned,
            'total_redeemed_all_time' => (int) $aggregates->total_redeemed,
            'expiring_soon'           => $expiringSoon,
            'buckets_count'           => (int) $aggregates->bucket_count,
            'buckets'                 => BucketResource::collection($bucketsPage->items())->toArray($request),
            'buckets_next_cursor'     => $bucketsPage->nextCursor()?->encode(),
            'buckets_has_more'        => $bucketsPage->nextCursor() !== null,
            'recent_entries'          => LedgerEntryResource::collection($recentEntries)->toArray($request),
            'currency'                => 'AZN',
        ]);
    }
}
