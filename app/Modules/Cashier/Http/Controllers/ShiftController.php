<?php

declare(strict_types=1);

namespace App\Modules\Cashier\Http\Controllers;

use App\Core\Enums\TransactionStatus;
use App\Core\Models\Transaction;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function index(Request $request): Response
    {
        $cashier    = $request->user();
        $merchantId = (int) $cashier->merchant_id;
        $shiftStart = now()->startOfDay();

        // Audit Csh-2: defense-in-depth — `cashier_id` artıq filtr edir, lakin
        // `merchant_id` cədvələ açıq sutun olduğundan eyni filtri əlavə etmək
        // mümkün hücumçu/index-friendly axın üçün ekstra qoruyucudur (kassir
        // başqa merchant-a transfer edilərsə legacy yazıları görsənməsin).
        $base = Transaction::query()
            ->where('cashier_id', $cashier->id)
            ->where('merchant_id', $merchantId)
            ->where('occurred_at', '>=', $shiftStart);

        // Audit Csh-3: aggregation-ı SQL-də et — bütün shift-i memory-yə yükləmə.
        // Audit Csh-4: completed/reversed/refunded ayrı counter; totalSales yalnız
        // completed-i hesablayır ki, geri qaytarılmış satış shift məbləğini
        // sun'i şişirtməsin.
        $stats = (clone $base)
            ->selectRaw(
                'COUNT(*)                                                          AS total_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)                       AS completed_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)                       AS reversed_count,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END)                       AS refunded_count,
                 SUM(CASE WHEN status = ? THEN sale_amount     ELSE 0 END)         AS total_sales,
                 SUM(CASE WHEN status = ? THEN earned_amount   ELSE 0 END)         AS total_earned,
                 SUM(CASE WHEN status = ? THEN redeemed_amount ELSE 0 END)         AS total_redeemed',
                [
                    TransactionStatus::Completed->value,
                    TransactionStatus::Reversed->value,
                    TransactionStatus::Refunded->value,
                    TransactionStatus::Completed->value,
                    TransactionStatus::Completed->value,
                    TransactionStatus::Completed->value,
                ],
            )
            ->first();

        // Audit Csh-3: SQL `LIMIT 20` — memory-collection take(20) əvəzinə.
        $recent = (clone $base)
            ->with(['customer:id,name'])
            ->latest('occurred_at')
            ->limit(20)
            ->get();

        return Inertia::render('Cashier/Shift', [
            'cashier' => [
                'id'       => $cashier->id,
                'name'     => $cashier->name,
                'merchant' => $cashier->merchant->only(['id', 'code', 'name']),
            ],
            'shiftStats' => [
                'transactions'   => (int) $stats->total_count,
                'completedCount' => (int) $stats->completed_count,
                'reversedCount'  => (int) $stats->reversed_count,
                'refundedCount'  => (int) $stats->refunded_count,
                'totalSales'     => (int) $stats->total_sales,
                'totalEarned'    => (int) $stats->total_earned,
                'totalRedeemed'  => (int) $stats->total_redeemed,
                'startedAt'      => $shiftStart->toIso8601String(),
            ],
            'recentTransactions' => $recent->values(),
        ]);
    }
}
