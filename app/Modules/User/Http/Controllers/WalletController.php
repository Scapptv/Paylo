<?php

declare(strict_types=1);

namespace App\Modules\User\Http\Controllers;

use App\Core\Models\LedgerEntry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Müştəri wallet-i. UI cəm balans göstərir; toxununca per-merchant breakdown.
 */
class WalletController extends Controller
{
    /** Audit Usr-1: web-də "Daha çox göstər" UI üçün ilk səhifə limiti. */
    private const BUCKETS_PER_PAGE = 30;

    public function show(Request $request): Response
    {
        $user = $request->user();

        // Aggregate — bütün bucket-lər üzərində (pagination wallet məbləğini təhrif etməsin).
        $aggregates = $user->buckets()
            ->selectRaw('COUNT(*) AS bucket_count, COALESCE(SUM(balance), 0) AS total_balance')
            ->first();

        // Yalnız ilk 30 bucket göstərilir; əgər `bucket_count > 30`-dirsə UI
        // "Daha çox göstər" düyməsi üçün dəstək yarada bilər (mobile-dəki
        // /api/v1/wallet endpoint-i cursor-paginated-dir).
        $buckets = $user->buckets()
            ->with('merchant:id,code,name,category')
            ->orderByDesc('balance')
            ->limit(self::BUCKETS_PER_PAGE)
            ->get();

        $recentEntries = LedgerEntry::with('merchant:id,code,name,category')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('User/Wallet', [
            'customer' => [
                'id'   => $user->id,
                'name' => $user->name,
                'qr'   => $user->customer_qr,
            ],
            'totalBalance'  => (int) $aggregates->total_balance,
            'bucketsCount'  => (int) $aggregates->bucket_count,
            'buckets'       => $buckets,
            'recentEntries' => $recentEntries,
        ]);
    }
}
