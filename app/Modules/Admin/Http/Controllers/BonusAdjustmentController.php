<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\BonusAdjustmentRequest;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin "manual bonus düzəlişi" — Audit 2026-06-04 CANON-4 + Admin roadmap Phase 1.1.
 *
 * YALNIZ CREDIT (bonus əlavə edir). `LedgerService::adjust()` kontraktı credit-only-dur
 * (audit C-3): goodwill, şikayət həlli, və ya çatışmayan reverse-dən sonra redeem-i
 * manual geri qaytarmaq üçün bərpa yolu. Bonus AZALTMAQ debit istiqamətidir —
 * reverse/refund vasitəsilə.
 *
 * İki səth:
 *  - **Admin UI (Inertia):** `create()` forması; `email` ilə müştəri seçilir → redirect.
 *  - **API (JSON):** `customer_id` ilə (POSNET/proqram); 201 JSON cavabı (kontrakt sabit).
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

    /** GET /admin/bonus-adjustments — manual kredit forması (admin UI). */
    public function create(): InertiaResponse
    {
        return Inertia::render('Admin/BonusAdjustment', [
            'merchants' => Merchant::query()->orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    /** POST /admin/bonus-adjustments — müştəri bucket-inə manual CREDIT. */
    public function store(BonusAdjustmentRequest $request): Response
    {
        // customer_id (API) və ya email (admin UI) — BonusAdjustmentRequest birini tələb edir.
        $customer = $request->filled('customer_id')
            ? User::findOrFail($request->integer('customer_id'))
            : User::where('email', (string) $request->input('email'))
                ->where('role', UserRole::Customer)
                ->where('is_active', true)
                ->firstOrFail();

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

        // Inertia (web form) → redirect + flash (HandleInertiaRequests `flash.success`
        // paylaşır); API (JSON) → 201 (kontrakt sabit).
        if (! $request->expectsJson()) {
            return back()->with('success', sprintf(
                '%d qəpik kredit edildi — %s @ %s · yeni balans: %d qəpik.',
                $amount->amount,
                $customer->name,
                $merchant->name,
                (int) $bucket->balance,
            ));
        }

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
