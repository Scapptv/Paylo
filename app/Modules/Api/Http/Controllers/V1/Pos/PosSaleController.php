<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1\Pos;

use App\Core\Enums\TransactionStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Core\Services\LedgerService;
use App\Core\Services\ReverseFlowService;
use App\Http\Controllers\Controller;
use App\Modules\Api\Http\Requests\V1\Pos\PosCompleteSaleRequest;
use App\Modules\Api\Http\Requests\V1\Pos\PosLookupCustomerRequest;
use App\Modules\Api\Http\Requests\V1\Pos\PosPreviewSaleRequest;
use App\Modules\Api\Http\Requests\V1\Pos\PosReverseSaleRequest;
use App\Modules\Api\Http\Requests\V1\Pos\PosTransactionFeedRequest;
use App\Modules\Api\Http\Resources\V1\Pos\PosTransactionResource;
use App\Modules\Api\Services\RotatingQrService;
use App\Modules\Pos\Services\SaleAmountComputer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POS API — POSNET (Python/FastAPI) və ya digər kassir sistemləri üçün
 * machine-to-machine endpoint-lər. Web kassir UI-ından tam izolyasiyalı:
 *
 *  - Auth: Sanctum bearer token, ability `pos:write` (kassir/customer ability-dən ayrı).
 *  - Merchant scope: token sahibinin (User) `merchant_id`-sindən gəlir; payload
 *    ilə override edilə bilməz.
 *  - Idempotency: iki səviyyə — (a) `Idempotency-Key` header (cache-əsaslı
 *    response replay, IdempotencyKey middleware), (b) domain-level
 *    `(merchant_id, receipt_no)` unique constraint.
 *  - Audit channel: `api.pos.*` event-ləri AuditLogger vasitəsilə.
 *
 * Biznes məntiqi web `Modules\Pos\Http\Controllers\SaleController`-dən
 * fərqli deyil. Hesablama eyni `SaleAmountComputer`-dən, ledger eyni
 * `LedgerService`-dən, reverse eyni `ReverseFlowService`-dən gəlir.
 */
final class PosSaleController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly RotatingQrService $rotatingQr,
        private readonly ReverseFlowService $reverseFlow,
        private readonly SaleAmountComputer $amounts,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * POST /api/v1/pos/customer/lookup — QR kodla müştəri tap.
     *
     * Web SaleController::lookupCustomer-dən fərqlər:
     *  - Method POST (body-də QR), GET deyil — QR URL log-larında görünməsin.
     *  - Merchant token sahibindən gəlir, EnsureMerchantScope middleware-i yox.
     *  - Audit channel `api.pos.customer.lookup` (web `pos.customer.lookup`-dan ayrı).
     *
     * Cavab strukturu web ilə eynidir — POSNET və Inertia kassir UI eyni
     * lookup formatını gözləyir.
     */
    public function lookupCustomer(PosLookupCustomerRequest $request): JsonResponse
    {
        $merchantId = $this->merchantIdFromToken($request);
        $qr         = $request->string('qr')->toString();
        $qrHash     = hash('sha256', $qr);

        $resolved = $this->resolveQr($qr);

        if ($resolved['user_qr'] === null) {
            $this->audit->log('api.pos.customer.lookup', [
                'merchant_id' => $merchantId,
                'token_id'    => $request->user()->currentAccessToken()?->id,
                'qr_hash'     => $qrHash,
                'mode'        => $resolved['mode'],
                'status'      => 'not_found',
                'reason'      => $resolved['reason'],
            ], $request);

            // Enumeration qarşısı: 200 + ['status' => 'not_found'] həm valid həm
            // invalid QR üçün eyni HTTP status; yalnız body fərqlənir.
            return response()->json([
                'status'   => 'not_found',
                'customer' => null,
                'bucket'   => null,
            ]);
        }

        $customer = User::where('customer_qr', $resolved['user_qr'])
            ->where('role', UserRole::Customer)
            ->where('is_active', true)
            ->first();

        if (! $customer) {
            $this->audit->log('api.pos.customer.lookup', [
                'merchant_id' => $merchantId,
                'token_id'    => $request->user()->currentAccessToken()?->id,
                'qr_hash'     => $qrHash,
                'mode'        => $resolved['mode'],
                'status'      => 'not_found',
                'reason'      => 'user_missing',
            ], $request);

            return response()->json([
                'status'   => 'not_found',
                'customer' => null,
                'bucket'   => null,
            ]);
        }

        // Rotating token replay protection. Cache xətası lookup-u bloklamır —
        // worst-case 60s replay pəncərəsi açır, lakin token-leak vəziyyəti
        // onsuz da token rotation tələb edir.
        if ($resolved['mode'] === 'rotating' && $resolved['hmac'] !== null) {
            try {
                $this->rotatingQr->markUsed($resolved['hmac']);
            } catch (\Throwable $e) {
                $this->audit->log('api.pos.customer.lookup.mark_used_failed', [
                    'merchant_id' => $merchantId,
                    'qr_hash'     => $qrHash,
                    'error'       => $e->getMessage(),
                ], $request);
            }
        }

        $bucket = Bucket::firstOrNew(['user_id' => $customer->id, 'merchant_id' => $merchantId]);

        $this->audit->log('api.pos.customer.lookup', [
            'merchant_id' => $merchantId,
            'token_id'    => $request->user()->currentAccessToken()?->id,
            'qr_hash'     => $qrHash,
            'mode'        => $resolved['mode'],
            'status'      => 'ok',
            'customer_id' => $customer->id,
        ], $request);

        return response()->json([
            'status'   => 'ok',
            'customer' => [
                'id'   => $customer->id,
                'name' => $customer->name,
            ],
            'bucket' => [
                'balance'        => (int) ($bucket->balance ?? 0),
                'earned_total'   => (int) ($bucket->earned_total ?? 0),
                'redeemed_total' => (int) ($bucket->redeemed_total ?? 0),
            ],
        ]);
    }

    /** POST /api/v1/pos/sale/preview — satışı yazmadan preview. */
    public function preview(PosPreviewSaleRequest $request): JsonResponse
    {
        $merchant   = Merchant::findOrFail($this->merchantIdFromToken($request));
        $customer   = User::findOrFail($request->integer('customer_id'));
        $bucket     = Bucket::firstOrNew(['user_id' => $customer->id, 'merchant_id' => $merchant->id]);

        $computed = $this->amounts->compute(
            saleAmountCents: $request->integer('sale_amount_cents'),
            useBonus:        $request->boolean('use_bonus'),
            redeemCentsRaw:  $request->integer('redeem_cents'),
            merchant:        $merchant,
            bucketBalance:   (int) ($bucket->balance ?? 0),
        );

        return response()->json([
            'sale_amount'       => $computed['sale']->amount,
            'earn_amount'       => $computed['earn']->amount,
            'redeem_amount'     => $computed['redeem']->amount,
            'final_to_pay'      => $computed['sale']->amount - $computed['redeem']->amount,
            'projected_balance' => (int) ($bucket->balance ?? 0)
                                   - $computed['redeem']->amount
                                   + $computed['earn']->amount,
        ]);
    }

    /**
     * POST /api/v1/pos/sale — satışı tamamla, ledger-ə yaz.
     *
     * Iki səviyyəli idempotency:
     *  1. Header `Idempotency-Key` — IdempotencyKey middleware-i tərəfindən
     *     handle olunur (response replay). Bu controller-ə çatmır.
     *  2. Domain-level: (merchant_id, receipt_no) unique. Web SaleController
     *     ilə eyni pattern — POSNET retry/timeout zamanı eyni qəbz təkrar
     *     gönderilərsə yeni earn yazılmır.
     */
    public function complete(PosCompleteSaleRequest $request): JsonResponse
    {
        $merchant   = Merchant::findOrFail($this->merchantIdFromToken($request));
        $customer   = User::findOrFail($request->integer('customer_id'));
        $cashierId  = (int) $request->user()->id;
        $receiptNo  = $request->string('receipt_no')->toString();
        $branchId   = $request->integer('branch_id') ?: null;

        try {
            $response = DB::transaction(function () use (
                $merchant, $customer, $cashierId, $receiptNo, $branchId, $request
            ) {
                $existing = Transaction::where('merchant_id', $merchant->id)
                    ->where('receipt_no', $receiptNo)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->idempotentCompleteResponse($existing);
                }

                $bucket = Bucket::where('user_id', $customer->id)
                    ->where('merchant_id', $merchant->id)
                    ->lockForUpdate()
                    ->first();

                $computed = $this->amounts->compute(
                    saleAmountCents: $request->integer('sale_amount_cents'),
                    useBonus:        $request->boolean('use_bonus'),
                    redeemCentsRaw:  $request->integer('redeem_cents'),
                    merchant:        $merchant,
                    bucketBalance:   (int) ($bucket->balance ?? 0),
                );

                $tx = Transaction::create([
                    'receipt_no'      => $receiptNo,
                    'merchant_id'     => $merchant->id,
                    'branch_id'       => $branchId,
                    'cashier_id'      => $cashierId,
                    'user_id'         => $customer->id,
                    'sale_amount'     => $computed['sale']->amount,
                    'earned_amount'   => $computed['earn']->amount,
                    'redeemed_amount' => $computed['redeem']->amount,
                    'status'          => TransactionStatus::Completed,
                    'occurred_at'     => now(),
                ]);

                if (! $computed['redeem']->isZero()) {
                    $this->ledger->redeem(
                        customer:  $customer,
                        merchant:  $merchant,
                        amount:    $computed['redeem'],
                        receiptNo: $tx->receipt_no,
                        branchId:  $tx->branch_id,
                        cashierId: $cashierId,
                        meta:      ['transaction_id' => $tx->id, 'channel' => 'api'],
                    );
                }

                if (! $computed['earn']->isZero()) {
                    $this->ledger->earn(
                        customer:  $customer,
                        merchant:  $merchant,
                        amount:    $computed['earn'],
                        receiptNo: $tx->receipt_no,
                        branchId:  $tx->branch_id,
                        cashierId: $cashierId,
                        meta:      ['transaction_id' => $tx->id, 'channel' => 'api'],
                    );
                }

                return response()->json([
                    'transaction_id' => $tx->id,
                    'receipt_no'     => $tx->receipt_no,
                    'status'         => TransactionStatus::Completed->value,
                    'idempotent'     => false,
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Paralel sorğu yarışı: eyni (merchant_id, receipt_no) artıq insert edilib.
            // Idempotent retry kimi davran — mövcud tx-i qaytar.
            $winner = Transaction::where('merchant_id', $merchant->id)
                ->where('receipt_no', $receiptNo)
                ->first();

            if ($winner === null) {
                throw $e;
            }

            $this->audit->log('api.pos.sale.complete.idempotent_race', [
                'merchant_id' => $merchant->id,
                'token_id'    => $request->user()->currentAccessToken()?->id,
                'tx_id'       => $winner->id,
                'receipt_no'  => $winner->receipt_no,
            ], $request);

            $response = $this->idempotentCompleteResponse($winner);
        }

        $this->audit->log('api.pos.sale.complete', [
            'merchant_id' => $merchant->id,
            'token_id'    => $request->user()->currentAccessToken()?->id,
            'customer_id' => $customer->id,
            'receipt_no'  => $receiptNo,
            'idempotent'  => $response->getData()->idempotent ?? false,
        ], $request);

        return $response;
    }

    /**
     * POST /api/v1/pos/sale/{receipt_no}/reverse — satışı geri qaytar.
     *
     * Web tərəfdə bu endpoint yalnız merchant_owner/staff/admin üçün açıqdır
     * (kassir səviyyəsində refund vəzifə bölgüsünü pozur — audit P-4).
     *
     * Audit 2026-06-04 CANON-1: API tərəfdə reverse ƏLAVƏ `pos:reverse` ability-si
     * TƏLƏB EDİR (route `app/Modules/Api/Routes/api.php`-də `ability:pos:reverse`
     * ilə qorunur; `PosApiSecurityTest` doğrulayır). `pos:write`-only token reverse
     * edə BİLMƏZ — sızdırılmış satış token-i ilə müştəri bonusunun batch drenajının
     * qarşısı alınır. `pos:reverse` token-i yalnız operator istəyəndə
     * (`pos:issue-token --include-reverse`) verilir; mağazanın daxili kassir-vs-müdir
     * səlahiyyəti POSNET tərəfdə həll olunur.
     */
    public function reverse(PosReverseSaleRequest $request, string $receiptNo): JsonResponse
    {
        $merchantId      = $this->merchantIdFromToken($request);
        $actorId         = (int) $request->user()->id;
        $returnReceiptNo = (string) $request->input('return_receipt_no');
        $reason          = $request->input('reason');

        $tx = Transaction::where('merchant_id', $merchantId)
            ->where('receipt_no', $receiptNo)
            ->first();

        $result = $this->reverseFlow->execute($tx, $actorId, $returnReceiptNo, $reason, 'api.pos.sale.reverse');

        return response()->json(
            collect($result)->except('http_status')->all(),
            $result['http_status'],
        );
    }

    private function idempotentCompleteResponse(Transaction $tx): JsonResponse
    {
        return response()->json([
            'transaction_id' => $tx->id,
            'receipt_no'     => $tx->receipt_no,
            'status'         => $tx->status->value,
            'idempotent'     => true,
        ]);
    }

    /**
     * GET /api/v1/pos/transactions — Reconciliation feed.
     *
     * POSNET öz lokal sale qeydlərini Paylo-nun yazılmış vəziyyəti ilə təsdiq
     * edir. Network failure ssenarisi:
     *   1. POSNET `/api/v1/pos/sale` çağırır, Paylo commit edir.
     *   2. HTTP cavab POSNET-ə çatmır (timeout, packet drop).
     *   3. POSNET retry-də `Idempotency-Key` ilə eyni cavabı alır (cache replay).
     *   4. Lakin cache TTL bitsə (24h), POSNET artıq commit-i təsdiq edə bilməz.
     *   5. Periodic reconciliation çəkir: "son sync-dan bəri hansı tx-lər var?"
     *
     * Cursor-paginated, occurred_at DESC sıralı (ən yenisi əvvəl). POSNET
     * `since` parametri ilə son uğurlu sync vaxtını ötürür — yalnız ondan
     * sonrakı tx-lər qaytarılır. Customer PII (email/phone) daxil deyil.
     *
     * Merchant scope avtomatik tətbiq olunur — token sahibinin merchant-ından
     * kənar tx-lər heç vaxt görünməz.
     */
    public function transactions(PosTransactionFeedRequest $request): JsonResponse
    {
        $merchantId = $this->merchantIdFromToken($request);
        $validated  = $request->validated();

        $query = Transaction::where('merchant_id', $merchantId);

        if (! empty($validated['since'])) {
            $query->where('occurred_at', '>=', Carbon::parse($validated['since']));
        }

        if (! empty($validated['until'])) {
            $query->where('occurred_at', '<=', Carbon::parse($validated['until']));
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $cursor = isset($validated['cursor'])
            ? Cursor::fromEncoded($validated['cursor'])
            : null;

        $limit = (int) ($validated['limit'] ?? 50);

        // Cursor pagination occurred_at + id üzərindədir — yeni insert-lər
        // pagination-ı sındırmır. SELECT açıq sütun siyahısı ilə (audit Api-15
        // ilə eyni motivasiya — sensitive sütun sızmasın).
        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate(
            $limit,
            ['id', 'merchant_id', 'branch_id', 'cashier_id', 'user_id',
             'receipt_no', 'sale_amount', 'earned_amount', 'redeemed_amount',
             'status', 'occurred_at', 'created_at'],
            'cursor',
            $cursor,
        );

        $this->audit->log('api.pos.transactions.feed', [
            'merchant_id' => $merchantId,
            'token_id'    => $request->user()->currentAccessToken()?->id,
            'since'       => $validated['since'] ?? null,
            'until'       => $validated['until'] ?? null,
            'status'      => $validated['status'] ?? null,
            'limit'       => $limit,
            'returned'    => count($page->items()),
        ], $request);

        return response()->json([
            'data'        => PosTransactionResource::collection($page->items())->toArray($request),
            'next_cursor' => $page->nextCursor()?->encode(),
            'has_more'    => $page->nextCursor() !== null,
        ]);
    }

    /**
     * Token sahibinin merchant_id-sini qaytarır. POSNET token-i `pos:write`
     * ability ilə verildiyi üçün sahib həmişə merchant-a bağlı bir user-dir
     * (pos_terminal və ya merchant_owner). merchant_id yoxdursa — issuance
     * boş bir tokenlə tamamlanıb, fail-fast 500.
     */
    private function merchantIdFromToken(Request $request): int
    {
        $id = (int) ($request->user()?->merchant_id ?? 0);

        if ($id <= 0) {
            abort(500, 'POS token sahibinin merchant_id-si təyin olunmayıb. Token-i yenidən IssuePosTokenCommand ilə verin.');
        }

        return $id;
    }

    /**
     * @return array{mode: string, user_qr: ?string, reason: ?string, hmac: ?string}
     */
    private function resolveQr(string $input): array
    {
        if (str_starts_with($input, RotatingQrService::VERSION . '.')) {
            $result = $this->rotatingQr->verify($input);

            return [
                'mode'    => 'rotating',
                'user_qr' => $result['valid'] ? $result['user_qr'] : null,
                'reason'  => $result['valid'] ? null : $result['reason'],
                'hmac'    => $result['valid'] ? ($result['hmac'] ?? null) : null,
            ];
        }

        $trimmed = trim($input);
        if ($trimmed === '' || strlen($trimmed) > 128) {
            return ['mode' => 'static', 'user_qr' => null, 'reason' => 'malformed', 'hmac' => null];
        }

        return [
            'mode'    => 'static',
            'user_qr' => $trimmed,
            'reason'  => null,
            'hmac'    => null,
        ];
    }
}
