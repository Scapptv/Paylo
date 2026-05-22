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
    public function show(Request $request): Response
    {
        $user = $request->user();

        $buckets = $user->buckets()
            ->with('merchant:id,code,name,category')
            ->orderByDesc('balance')
            ->get();

        $recentEntries = LedgerEntry::with('merchant:id,code,name,category')
            ->where('user_id', $user->id)
            ->latest()
            ->limit(20)
            ->get();

        return Inertia::render('User/Wallet', [
            'customer'      => [
                'id'   => $user->id,
                'name' => $user->name,
                'qr'   => $user->customer_qr,
            ],
            'totalBalance'  => (int) $buckets->sum('balance'),
            'buckets'       => $buckets,
            'recentEntries' => $recentEntries,
        ]);
    }
}
