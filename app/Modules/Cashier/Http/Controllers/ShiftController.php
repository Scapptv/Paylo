<?php

declare(strict_types=1);

namespace App\Modules\Cashier\Http\Controllers;

use App\Core\Models\Transaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $cashier  = $request->user();
        $shiftStart = now()->startOfDay();

        $shiftTx = Transaction::with(['customer:id,name'])
            ->where('cashier_id', $cashier->id)
            ->where('occurred_at', '>=', $shiftStart)
            ->latest('occurred_at')
            ->get();

        return Inertia::render('Cashier/Shift', [
            'cashier' => [
                'id'       => $cashier->id,
                'name'     => $cashier->name,
                'merchant' => $cashier->merchant->only(['id', 'code', 'name']),
            ],
            'shiftStats' => [
                'transactions'  => $shiftTx->count(),
                'totalSales'    => (int) $shiftTx->sum('sale_amount'),
                'totalEarned'   => (int) $shiftTx->sum('earned_amount'),
                'totalRedeemed' => (int) $shiftTx->sum('redeemed_amount'),
                'startedAt'     => $shiftStart->toIso8601String(),
            ],
            'recentTransactions' => $shiftTx->take(20)->values(),
        ]);
    }
}
