<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\BonusAdjustmentRequest;
use Illuminate\Http\JsonResponse;

/**
 * Admin "manual bonus düzəlişi" — Audit 2026-06-04 CANON-4.
 *
 * YALNIZ CREDIT (bonus əlavə edir). `LedgerService::adjust()` kontraktı credit-only-dur
 * (audit C-3): goodwill, şikayət həlli, və ya çatışmayan reverse-dən sonra redeem-i
 * manual geri qaytarmaq üçün bərpa yolu. Bonus AZALTMAQ debit istiqamətidir —
 * reverse/refund vasitəsilə.
 *
 * Bütün dəyişiklik append-only `LedgerService` vasitəsi ilə baş verir; bucket
 * counter-ləri eyni DB transaction-da yenilənir, immutable ledger meta-da
 * `admin_id` + `reason` audit izi kimi saxlanır.
 */
class BonusAdjustmentController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {
    }

    /** POST /admin/bonus-adjustments — müştəri bucket-inə manual kredit. */
    public function store(BonusAdjustmentRequest $request): JsonResponse
    {
        $customer = User::findOrFail($request->integer('customer_id'));
        $merchant = Merchant::findOrFail($request->integer('merchant_id'));
        $amount   = new BonusValue($request->integer('amount_cents'));
        $reason   = (string) $request->input('reason');
        $adminId  = (int) $request->user()->id;

        // adjust() lockOrCreateBucket istifadə edir — bucket yoxdursa yaradır.
        $entry = $this->ledger->adjust(
            customer: $customer,
            merchant: $merchant,
            amount:   $amount,
            reason:   $reason,
            adminId:  $adminId,
        );

        $bucket = Bucket::where('user_id', $customer->id)
            ->where('merchant_id', $merchant->id)
            ->firstOrFail();

        $this->audit->log('admin.bonus.adjustment', [
            'admin_id'    => $adminId,
            'customer_id' => $customer->id,
            'merchant_id' => $merchant->id,
            'amount'      => $amount->amount,
            'entry_uid'   => $entry->uid,
            'reason'      => $reason,
        ], $request);

        return response()->json([
            'status' => 'ok',
            'entry'  => [
                'uid'    => $entry->uid,
                'type'   => $entry->type->value,
                'amount' => $entry->amount,
            ],
            'bucket' => [
                'balance'        => (int) $bucket->balance,
                'earned_total'   => (int) $bucket->earned_total,
                'redeemed_total' => (int) $bucket->redeemed_total,
            ],
        ], 201);
    }
}
