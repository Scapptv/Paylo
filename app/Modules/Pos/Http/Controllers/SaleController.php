<?php

declare(strict_types=1);

namespace App\Modules\Pos\Http\Controllers;

use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use App\Http\Controllers\Controller;
use App\Modules\Api\Services\RotatingQrService;
use App\Modules\Pos\Http\Requests\PreviewSaleRequest;
use App\Modules\Pos\Http\Requests\CompleteSaleRequest;
use App\Modules\Pos\Http\Requests\ReverseSaleRequest;
use App\Modules\Pos\Services\EarnCalculator;
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
        private readonly EarnCalculator $earn,
        private readonly RotatingQrService $rotatingQr,
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

        $customer = User::where('customer_qr', $resolved['user_qr'])
            ->where('role', 'customer')
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
        if ($resolved['mode'] === 'rotating' && $resolved['hmac'] !== null) {
            $this->rotatingQr->markUsed($resolved['hmac']);
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

        return response()->json([
            'status'   => 'ok',
            'customer' => [
                'id'   => $customer->id,
                'name' => $customer->name,
                'qr'   => $customer->customer_qr,
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

        $computed = $this->computeAmounts(
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
     * Race condition: lookup və insert eyni DB transaction içindədir.
     * (merchant_id, receipt_no) unique constraint (migration-da) ikiqat müdafiə kimi
     * çıxış edir — paralel iki request gəlsə də ikincisi QueryException-a düşür və
     * idempotent retry zamanı tutula bilər.
     */
    public function complete(CompleteSaleRequest $request): JsonResponse
    {
        $merchant   = Merchant::findOrFail($this->merchantId($request));
        $customer   = User::findOrFail($request->integer('customer_id'));
        $cashierId  = (int) $request->user()->id;
        $receiptNo  = $request->string('receipt_no')->toString();
        $branchId   = $request->integer('branch_id') ?: null;

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
                return response()->json([
                    'transaction_id' => $existing->id,
                    'receipt_no'     => $existing->receipt_no,
                    'status'         => $existing->status,
                    'idempotent'     => true,
                ]);
            }

            // 2) Yeni satış — bucket balansını oxu, eyni formula ilə hesabla.
            $bucket = Bucket::where('user_id', $customer->id)
                ->where('merchant_id', $merchant->id)
                ->lockForUpdate()
                ->first();

            $computed = $this->computeAmounts(
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
                'status'          => 'completed',
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
                'status'         => 'completed',
                'idempotent'     => false,
            ]);
        });
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

        if (! $tx) {
            return response()->json([
                'status'  => 'not_found',
                'message' => 'Bu qəbz tapılmadı.',
            ], 404);
        }

        // Idempotent qisa-çıxış — yeni iş görməmək üçün servisə girmədən.
        if ($tx->status === 'reversed') {
            return response()->json([
                'transaction_id'   => $tx->id,
                'receipt_no'       => $tx->receipt_no,
                'status'           => 'reversed',
                'already_reversed' => true,
                'reverse_entries'  => [],
            ]);
        }

        try {
            $entries = $this->ledger->reverseTransaction($tx, $cashierId, $returnReceiptNo, $reason);
        } catch (\RuntimeException $e) {
            // İki səbəb mümkündür:
            //  1) Race: paralel sorğu artıq reverse etdi → idempotent 200 qaytaraq.
            //  2) Müştəri bonus-u xərcləyib → 422.
            $tx->refresh();
            if ($tx->status === 'reversed') {
                return response()->json([
                    'transaction_id'   => $tx->id,
                    'receipt_no'       => $tx->receipt_no,
                    'status'           => 'reversed',
                    'already_reversed' => true,
                    'reverse_entries'  => [],
                ]);
            }

            Log::warning('pos.sale.reverse.failed', [
                'merchant_id' => $merchantId,
                'cashier_id'  => $cashierId,
                'tx_id'       => $tx->id,
                'receipt_no'  => $tx->receipt_no,
                'reason'      => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => 'unprocessable',
                'message' => $e->getMessage(),
            ], 422);
        }

        Log::info('pos.sale.reverse.ok', [
            'merchant_id'   => $merchantId,
            'cashier_id'    => $cashierId,
            'tx_id'         => $tx->id,
            'receipt_no'    => $tx->receipt_no,
            'entries_count' => count($entries),
        ]);

        return response()->json([
            'transaction_id'   => $tx->id,
            'receipt_no'       => $tx->receipt_no,
            'status'           => 'reversed',
            'already_reversed' => false,
            'reverse_entries'  => array_map(
                fn ($e) => ['uid' => $e->uid, 'type' => $e->type->value, 'amount' => $e->amount],
                $entries,
            ),
        ]);
    }

    /**
     * preview və complete üçün TƏK hesablama yolu. Bu sayəsində preview-da göstərilən
     * earn/redeem cents complete-də eyni nəticəni verir.
     *
     * @return array{sale: BonusValue, earn: BonusValue, redeem: BonusValue}
     */
    private function computeAmounts(
        int $saleAmountCents,
        bool $useBonus,
        int $redeemCentsRaw,
        Merchant $merchant,
        int $bucketBalance,
    ): array {
        $sale = new BonusValue($saleAmountCents);
        $earn = $this->earn->calculate($merchant, $sale);

        if (! $useBonus) {
            $redeem = BonusValue::zero();
        } else {
            // redeem heç vaxt bucket balansından və ya satış məbləğindən böyük ola bilməz.
            $cap    = min($bucketBalance, $sale->amount);
            $redeem = new BonusValue(max(0, min($redeemCentsRaw, $cap)));
        }

        return ['sale' => $sale, 'earn' => $earn, 'redeem' => $redeem];
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
