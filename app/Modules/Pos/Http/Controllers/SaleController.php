<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Core\Enums\TransactionStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\Services\ReverseFlowService;
use App\Http\Controllers\Controller;
use App\Modules\Api\Services\RotatingQrService;
use App\Modules\Pos\Http\Requests\PreviewSaleRequest;
use App\Modules\Pos\Http\Requests\CompleteSaleRequest;
use App\Modules\Pos\Http\Requests\ReverseSaleRequest;
use App\Modules\Pos\Services\SaleAmountComputer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS Kit: kassirin satış zamanı istifadə etdiyi əsas controller.
 *  - lookupCustomer(qr): müştərinin bu merchant-dakı bucket-ini qaytarır
 *  - preview(amount, redeem): satışı yazmadan necə görünəcəyini hesablayır
 *  - complete(): satışı və ledger entry-ləri atomik yazır
 */
class SaleController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly RotatingQrService $rotatingQr,
        private readonly ReverseFlowService $reverseFlow,
        private readonly SaleAmountComputer $amounts,
    ) {
    }

    /** Yeni satış ekranı */
    public function show(Request $request): Response
    {
        return Inertia::render('Pos/Sale', [
            'merchant' => $request->user()->merchant->only(['id', 'code', 'name', 'category']),
        ]);
    }

    /** GET /pos/customer/{qr} — QR kodla müştəri tap.
     *
     * Qəbul olunan formatlar:
     *  1) Rotating token (canonical):  `qr1.{customer_qr}.{exp_unix}.{hmac16}`
     *     - `RotatingQrService::verify()` ilə HMAC + expiry + replay yoxlanır
     *     - Uğurlu lookup-dan sonra token cache-də `markUsed` edilir (60s TTL):
     *       eyni token-i cashier ikinci dəfə skan edə bilməz; mobile hər ~25s
     *       yenisini generasiya edir, deməli legitimate axın pozulmur.
     *  2) Static customer_qr fallback — manual giriş və ya admin recovery üçün.
     *     Yalnız `qr1.` prefiks olmayan input bu yola düşür.
     *
     * Təhlükəsizlik:
     *  - Route throttle:30,1 ilə qorunur (QR enumeration-a qarşı).
     *  - Uğurlu/uğursuz cavab eyni HTTP status (200) və eyni JSON struktur qaytarır;
     *    yalnız `status` sahəsi `'ok'` və ya `'not_found'` olur — attacker 404 vs 200 ilə
     *    QR-ları enumerate edə bilməsin.
     *  - Constant-time HMAC müqayisəsi `hash_equals` ilə.
     *  - Hər lookup audit log-a yazılır: merchant_id, cashier id, QR-ın sha256 hash-i
     *    (RAW QR PLAIN-TEXT YAZILMIR), token mode, fail reason (varsa), timestamp.
     */
    public function lookupCustomer(Request $request, string $qr): JsonResponse
    {
        $merchantId = $this->merchantId($request);
        $cashierId  = (int) $request->user()->id;
        $qrHash     = hash('sha256', $qr);

        $resolved = $this->resolveQr($qr);

        if ($resolved['user_qr'] === null) {
            Log::info('pos.customer.lookup', [
                'merchant_id' => $merchantId,
                'cashier_id'  => $cashierId,
                'qr_hash'     => $qrHash,
                'mode'        => $resolved['mode'],
                'status'      => 'not_found',
                'reason'      => $resolved['reason'],
                'at'          => now()->toIso8601String(),
            ]);

            // Eyni struktura sahib uğursuz cavab — enumeration imkanı yaratmır.
            return response()->json([
                'status'   => 'not_found',
                'customer' => null,
                'bucket'   => null,
            ]);
        }

        // Audit P-1: `is_active=false` (deaktivləşdirilmiş / anonimləşdirilmiş)
        // istifadəçi rotating QR-i mövcud olsa belə POS-da görünməməlidir —
        // əks halda silinmiş hesaba təsadüfən bonus yazılar.
        // Audit P-5: magic string `'customer'` əvəzinə UserRole enum dəyəri.
        $customer = User::where('customer_qr', $resolved['user_qr'])
            ->where('role', UserRole::Customer)
            ->where('is_active', true)
            ->first();

        if (! $customer) {
            Log::info('pos.customer.lookup', [
                'merchant_id' => $merchantId,
                'cashier_id'  => $cashierId,
                'qr_hash'     => $qrHash,
                'mode'        => $resolved['mode'],
                'status'      => 'not_found',
                'reason'      => 'user_missing',
                'at'          => now()->toIso8601String(),
            ]);

            return response()->json([
                'status'   => 'not_found',
                'customer' => null,
                'bucket'   => null,
            ]);
        }

        // Rotating token uğurla verify olundu → replay protection üçün markla.
        // Static QR (manual) modunda mark-used tətbiq olunmur, çünki həmin QR sabitdir.
        //
        // Audit P-12: `markUsed` cache layer-ə yazır. Cache exception (Redis offline,
        // network glitch və s.) bütün POS lookup-u uğursuz etməməlidir — lookup özü
        // artıq HMAC + expiry + replay yoxlamasından keçib. Mark-used uğursuzluğu
        // worst-case 60s replay pəncərəsi açır, lakin satışı bloklamır. Səhvi log edib
        // davam edirik (security trade-off availability lehinə).
        if ($resolved['mode'] === 'rotating' && $resolved['hmac'] !== null) {
            try {
                $this->rotatingQr->markUsed($resolved['hmac']);
            } catch (\Throwable $e) {
                // Sprint 6.3: log + Sentry-də ayrıca warning event. P-12 audit
                // qərarına görə bu səhv POS axınını sındırmır, lakin görünmür
                // olmamalıdır — replay window açıqdır.
                Log::warning('pos.customer.lookup.mark_used_failed', [
                    'merchant_id' => $merchantId,
                    'cashier_id'  => $cashierId,
                    'qr_hash'     => $qrHash,
                    'error'       => $e->getMessage(),
                ]);

                if (app()->bound('sentry')) {
                    \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($merchantId, $cashierId, $qrHash): void {
                        $scope->setLevel(\Sentry\Severity::warning());
                        $scope->setContext('pos_lookup', [
                            'merchant_id' => $merchantId,
                            'cashier_id'  => $cashierId,
                            'qr_hash'     => $qrHash,
                            'event'       => 'mark_used_failed',
                        ]);
                    });
                    \Sentry\captureException($e);
                }
            }
        }

        $bucket = Bucket::firstOrNew(['user_id' => $customer->id, 'merchant_id' => $merchantId]);

        Log::info('pos.customer.lookup', [
            'merchant_id' => $merchantId,
            'cashier_id'  => $cashierId,
            'qr_hash'     => $qrHash,
            'mode'        => $resolved['mode'],
            'status'      => 'ok',
            'customer_id' => $customer->id,
            'at'          => now()->toIso8601String(),
        ]);

        // Audit P-2: Static `customer_qr` cashier-ə qaytarılmır — rotating QR
        // sisteminin mövcudluğunun səbəbi budur. Cashier sale flow üçün yalnız
        // `id`-yə ehtiyac duyur (subsequent preview / complete request-lər).
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

    /** POST /pos/preview — satışı yazmadan preview */
    public function preview(PreviewSaleRequest $request): JsonResponse
    {
        $merchant   = Merchant::findOrFail($this->merchantId($request));
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
     * POST /pos/sale — satışı tamamla, ledger-ə yaz.
     *
     * IDEMPOTENT: əgər eyni (merchant + receipt_no) cütü ilə artıq Transaction varsa,
     * yeni ledger entry yaratmır və mövcud transaction-u eyni response formatında qaytarır.
     * Bu davranış real POS retry/timeout ssenarilərinə uyğundur — şəbəkə uğursuzluğundan
     * sonra kassa eyni receipt üçün sorğunu təkrarlaya bilər, duplicate earn baş vermir.
     *
     * Race condition (audit P-3): `lockForUpdate->first()` boş sətir üçün bütün
     * DB-lərdə qlobal gap-lock qarantiyası vermir (Postgres, MySQL READ COMMITTED).
     * Paralel iki request idempotency lookup-dan keçib həm bir insert cəhd edə bilər.
     * Bu halda `unique(merchant_id, receipt_no)` constraint ikincini rədd edir →
     * `UniqueConstraintViolationException`. Bunu tutub mövcud transaction-u qaytaraq:
     * istifadəçi yenə idempotent response alır, 500 yox.
     */
    public function complete(CompleteSaleRequest $request): JsonResponse
    {
        $merchant   = Merchant::findOrFail($this->merchantId($request));
        $customer   = User::findOrFail($request->integer('customer_id'));
        $cashierId  = (int) $request->user()->id;
        $receiptNo  = $request->string('receipt_no')->toString();
        $branchId   = $request->integer('branch_id') ?: null;

        try {
            return DB::transaction(function () use (
                $merchant, $customer, $cashierId, $receiptNo, $branchId, $request
            ) {
                // 1) Idempotency lookup — eyni receipt artıq yazılıbsa, yeni iş görmə.
                //    lockForUpdate ilə paralel iki sorğunun yarış vəziyyətini blokla.
                $existing = Transaction::where('merchant_id', $merchant->id)
                    ->where('receipt_no', $receiptNo)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $this->idempotentCompleteResponse($existing);
                }

                // 2) Yeni satış — bucket balansını oxu, eyni formula ilə hesabla.
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
                    // Audit P-6 (trade-off, by design): `occurred_at` POS-un göndərdiyi
                    // vaxt deyil, server vaxtıdır. Üstünlük: offline POS-un təsadüfi
                    // və ya manipulyasiya edilmiş tarixini etibarsız kimi tutmuruq —
                    // server vaxtı yeganə həqiqət mənbəyidir, audit/settlement
                    // hesabat dəqiq olur. Mənfi: real "satış vaxtı" ilə yazılı vaxt
                    // arasında şəbəkə uzantısı qədər fərq ola bilər. Real POS offline
                    // mode tələb olduqda gələcəkdə `client_occurred_at` field-i
                    // əlavə etmək olar (server vaxtı yenə də audit üçün istinad).
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
                        meta:      ['transaction_id' => $tx->id],
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
                        meta:      ['transaction_id' => $tx->id],
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
            // Paralel request yarışda qalib gəlib eyni (merchant_id, receipt_no) ilə
            // artıq insert edib. Idempotent retry kimi davran: mövcud tx-i qaytar.
            $winner = Transaction::where('merchant_id', $merchant->id)
                ->where('receipt_no', $receiptNo)
                ->first();

            if ($winner === null) {
                // Bu praktiki olaraq mümkün deyil — unique violation digər tx-in
                // mövcud olduğunu sübut edir. Lakin DB anomaliyasından bizi qoru.
                throw $e;
            }

            Log::info('pos.sale.complete.idempotent_race', [
                'merchant_id' => $merchant->id,
                'cashier_id'  => $cashierId,
                'tx_id'       => $winner->id,
                'receipt_no'  => $winner->receipt_no,
            ]);

            return $this->idempotentCompleteResponse($winner);
        }
    }

    /**
     * `complete` endpoint-i üçün vahid idempotent cavab forması — həm pre-check
     * lookup-ı, həm də unique-violation race fallback-ı eyni JSON strukturu qaytarsın.
     */
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
     * POST /pos/sale/{receiptNo}/reverse — kassir öz mağazasının satışını ləğv edir.
     *
     * Davranış:
     *  - Tx merchant scope-dakı (cari kassirin mağazası) olmalıdır — başqa mağazanın
     *    qəbzini scope qadağan edir (404, enumeration-a qarşı eyni status).
     *  - IDEMPOTENT: artıq reversed-dirsə 200 + `already_reversed: true` qaytarır,
     *    yeni ledger entry yaranmır. (Pattern eyni `complete()` ilə uyğundur.)
     *  - Müştəri bonus-u artıq xərcləyibsə LedgerService refund-da
     *    RuntimeException atır → 422 + insan-oxunaqlı mesaj. Adminin manual
     *    adjustment etməsi tələb olunur.
     *  - Atomicity LedgerService::reverseTransaction içində DB::transaction-a
     *    bağlanır — bu controller əlavə transaction açmır.
     */
    public function reverse(ReverseSaleRequest $request, string $receiptNo): JsonResponse
    {
        $merchantId      = $this->merchantId($request);
        $cashierId       = (int) $request->user()->id;
        $returnReceiptNo = (string) $request->input('return_receipt_no');
        $reason          = $request->input('reason');

        $tx = Transaction::where('merchant_id', $merchantId)
            ->where('receipt_no', $receiptNo)
            ->first();

        // Sprint 8 D-1: reverse orkestrasiyası ReverseFlowService-də toplandı —
        // Admin endpoint-i ilə eyni shape və exception handling.
        $result = $this->reverseFlow->execute($tx, $cashierId, $returnReceiptNo, $reason, 'pos.sale.reverse');

        return response()->json(
            collect($result)->except('http_status')->all(),
            $result['http_status'],
        );
    }

    /**
     * Strict reader: merchant scope yoxdursa fail-fast. `getInt` 0 default-u bug-u
     * maskalayır (məsələn `Merchant::findOrFail(0)` 404 verir, lakin əsl səbəb middleware
     * konfiqurasiya xətasıdır). Bunun yerinə açıq 500 ilə dayan.
     */
    private function merchantId(Request $request): int
    {
        $id = $request->attributes->get('merchant_id');

        if (! is_int($id) || $id <= 0) {
            abort(500, 'Merchant scope middleware aktiv deyil — bu endpoint merchant-scoped olmalıdır.');
        }

        return $id;
    }

    /**
     * Scan input-unu canonical `customer_qr` dəyərinə çevir.
     *
     * Strategiya:
     *  - `qr1.` prefiksi varsa → rotating token. RotatingQrService::verify() ilə
     *    HMAC, expiry və replay yoxlanır. Yalnız valid olarsa user_qr qaytarılır.
     *  - Əks halda → static customer_qr fallback (manual giriş / admin recovery üçün).
     *
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

        // Static fallback — boş və ya açıq-aydın korlanmış inputu burada kəs.
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
