<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Transaction;
use App\Core\Services\LedgerService;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\ReverseTransactionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin transaction idarəetmə paneli.
 *
 * Mövcud Admin/LedgerController yalnız ledger-i oxuyur. Bu controller
 * SƏLAHIYYƏTLI əməliyyatları icra edir — şimdilik yalnız `reverse`.
 * Tx-i silmir, ledger-i mutate etmir; bütün dəyişikliklər yenə də append-only
 * LedgerService vasitəsi ilə baş verir.
 */
class TransactionController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {
    }

    /** GET /admin/transactions — siyahı + filter (status, merchant). */
    public function index(Request $request): Response
    {
        $transactions = Transaction::query()
            ->with([
                'customer:id,name,email',
                'merchant:id,code,name',
                'cashier:id,name',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('merchant_id'), fn ($q) => $q->where('merchant_id', $request->integer('merchant_id')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $needle = '%' . $request->string('q') . '%';
                $q->where('receipt_no', 'like', $needle);
            })
            ->latest('occurred_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Transactions', [
            'transactions' => $transactions,
            'filters'      => $request->only(['status', 'merchant_id', 'q']),
        ]);
    }

    /**
     * POST /admin/transactions/{transaction}/reverse — admin tərəfindən tam reverse.
     *
     * Davranış:
     *  - `reason` MƏCBURİDİR (audit) — ReverseTransactionRequest enforce edir.
     *  - IDEMPOTENT: artıq reversed-dirsə 200 + `already_reversed: true`.
     *  - Müştəri bonusu xərcləyibsə LedgerService refund-da exception atır → 422.
     *  - Bütün dəyişiklik LedgerService::reverseTransaction içində atomic-dir;
     *    burada əlavə DB transaction açılmır.
     */
    public function reverse(ReverseTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $adminId         = (int) $request->user()->id;
        $returnReceiptNo = (string) $request->input('return_receipt_no');
        $reason          = (string) $request->input('reason');

        if ($transaction->status === 'reversed') {
            return response()->json([
                'transaction_id'   => $transaction->id,
                'receipt_no'       => $transaction->receipt_no,
                'status'           => 'reversed',
                'already_reversed' => true,
                'reverse_entries'  => [],
            ]);
        }

        try {
            $entries = $this->ledger->reverseTransaction($transaction, $adminId, $returnReceiptNo, $reason);
        } catch (\RuntimeException $e) {
            $transaction->refresh();
            if ($transaction->status === 'reversed') {
                return response()->json([
                    'transaction_id'   => $transaction->id,
                    'receipt_no'       => $transaction->receipt_no,
                    'status'           => 'reversed',
                    'already_reversed' => true,
                    'reverse_entries'  => [],
                ]);
            }

            Log::warning('admin.transaction.reverse.failed', [
                'admin_id'   => $adminId,
                'tx_id'      => $transaction->id,
                'receipt_no' => $transaction->receipt_no,
                'reason'     => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'unprocessable',
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::info('admin.transaction.reverse.ok', [
            'admin_id'      => $adminId,
            'tx_id'         => $transaction->id,
            'receipt_no'    => $transaction->receipt_no,
            'reason'        => $reason,
            'entries_count' => count($entries),
        ]);

        return response()->json([
            'transaction_id'   => $transaction->id,
            'receipt_no'       => $transaction->receipt_no,
            'status'           => 'reversed',
            'already_reversed' => false,
            'reverse_entries'  => array_map(
                fn ($e) => ['uid' => $e->uid, 'type' => $e->type->value, 'amount' => $e->amount],
                $entries,
            ),
        ]);
    }
}
