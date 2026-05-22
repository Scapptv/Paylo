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
     */
    private const EXPIRING_SOON_THRESHOLD_DAYS = 335;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $buckets = $user->buckets()
            ->with('merchant:id,code,name,category,tier')
            ->orderByDesc('balance')
            ->get();

        $recentEntries = LedgerEntry::with('merchant:id,code,name,category')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(10)
            ->get();

        $expiringThreshold = Carbon::now()->subDays(self::EXPIRING_SOON_THRESHOLD_DAYS);
        $expiringSoon = (int) $buckets
            ->filter(fn ($bucket) => $bucket->last_activity_at !== null
                && $bucket->last_activity_at->lt($expiringThreshold))
            ->sum('balance');

        return response()->json([
            'total_balance'           => (int) $buckets->sum('balance'),
            'total_earned_all_time'   => (int) $buckets->sum('earned_total'),
            'total_redeemed_all_time' => (int) $buckets->sum('redeemed_total'),
            'expiring_soon'           => $expiringSoon,
            'buckets_count'           => $buckets->count(),
            'buckets'                 => BucketResource::collection($buckets)->toArray($request),
            'recent_entries'          => LedgerEntryResource::collection($recentEntries)->toArray($request),
            'currency'                => 'AZN',
        ]);
    }
}
