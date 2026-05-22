<?php

declare(strict_types=1);

namespace App\Modules\Merchant\Http\Controllers;

use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Transaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Merchant paneli: yalnız bu merchant-a aid məlumatlar.
 * `EnsureMerchantScope` middleware request-i merchant_id ilə bağlamış olur.
 */
class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        // Fail-fast: middleware scope set etməyibsə, 0-la sessiz davam etmə.
        $merchantId = $request->attributes->get('merchant_id');
        if (! is_int($merchantId) || $merchantId <= 0) {
            abort(500, 'Merchant scope middleware aktiv deyil — bu endpoint merchant-scoped olmalıdır.');
        }

        $since      = now()->subDays(30);

        return Inertia::render('Merchant/Dashboard', [
            'stats' => [
                'customers'       => Bucket::where('merchant_id', $merchantId)->count(),
                'totalLocked'     => (int) Bucket::where('merchant_id', $merchantId)->sum('balance'),
                'earned30d'       => (int) LedgerEntry::where('merchant_id', $merchantId)
                    ->where('type', 'earn')
                    ->where('created_at', '>=', $since)
                    ->sum('amount'),
                'redeemed30d'     => (int) LedgerEntry::where('merchant_id', $merchantId)
                    ->where('type', 'redeem')
                    ->where('created_at', '>=', $since)
                    ->sum('amount'),
                'transactions30d' => Transaction::where('merchant_id', $merchantId)
                    ->where('occurred_at', '>=', $since)
                    ->count(),
            ],
            'recentTransactions' => Transaction::with(['customer:id,name', 'cashier:id,name', 'branch:id,name'])
                ->where('merchant_id', $merchantId)
                ->latest('occurred_at')
                ->limit(15)
                ->get(),
            'topCustomers' => Bucket::with('user:id,name,phone')
                ->where('merchant_id', $merchantId)
                ->orderByDesc('earned_total')
                ->limit(10)
                ->get(),
        ]);
    }
}
