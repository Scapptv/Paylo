<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'totalUsers'      => User::where('role', 'customer')->count(),
                'totalMerchants'  => Merchant::where('status', 'active')->count(),
                'pendingMerchants'=> Merchant::where('status', 'pending')->count(),
                'totalBuckets'    => Bucket::count(),
                'totalLedger'     => LedgerEntry::count(),
                'totalLocked'     => (int) Bucket::sum('balance'),
                'last24hEntries'  => LedgerEntry::where('created_at', '>=', now()->subDay())->count(),
            ],
            'recentEntries' => LedgerEntry::with(['user:id,name', 'merchant:id,name,code'])
                ->latest()
                ->limit(20)
                ->get(['id', 'uid', 'user_id', 'merchant_id', 'type', 'amount', 'balance_after', 'created_at']),
            'topMerchants' => Merchant::withCount('ledgerEntries')
                ->orderByDesc('ledger_entries_count')
                ->limit(5)
                ->get(['id', 'code', 'name', 'category', 'tier', 'status']),
        ]);
    }
}
