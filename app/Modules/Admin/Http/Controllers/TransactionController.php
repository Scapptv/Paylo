<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Transaction;
use App\Core\Services\ReverseFlowService;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\ReverseTransactionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin transaction idarəetmə paneli.
 *
 * Mövcud Admin/LedgerController yalnız ledger-i oxuyur. Bu controller
 * SƏLAHIYYƏTLI əməliyyatları icra edir — şimdilik yalnız `reverse`.
 * Tx-i silmir, ledger-i mutate etmir; bütün dəyişikliklər yenə də append-only
 * LedgerService vasitəsi ilə baş verir.
 *
 * Sprint 8 D-1: reverse orkestrasiyası `ReverseFlowService`-ə köçürüldü —
 * POS endpoint-i ilə eyni axın, eyni response shape, eyni race handling.
 */
class TransactionController extends Controller
{
    public function __construct(private readonly ReverseFlowService $reverseFlow)
    {
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
     *  - Bütün dəyişiklik ReverseFlowService → LedgerService::reverseTransaction
     *    içində atomic-dir; burada əlavə DB transaction açılmır.
     */
    public function reverse(ReverseTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $result = $this->reverseFlow->execute(
            tx:              $transaction,
            actorId:         (int) $request->user()->id,
            returnReceiptNo: (string) $request->input('return_receipt_no'),
            reason:          (string) $request->input('reason'),
            logChannel:      'admin.transaction.reverse',
        );

        return response()->json(
            collect($result)->except('http_status')->all(),
            $result['http_status'],
        );
    }
}
