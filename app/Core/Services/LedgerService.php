<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\LedgerEntryType;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bütün bonus hərəkətlərinin VƏAHID giriş nöqtəsi.
 *
 * Hər metod:
 *   1. DB transaction içində icra olunur
 *   2. Bucket-i lock edir (SELECT ... FOR UPDATE)
 *   3. Bir ledger entry yaradır
 *   4. Bucket counter-lərini yeniləyir
 *
 * **Cross-merchant transfer yoxdur**: redeem yalnız bonusun qazanıldığı bucket-dən mümkündür.
 */
final class LedgerService
{
    /**
     * Müştəri merchant-da satış edib — bucket-ə bonus əlavə et.
     */
    public function earn(
        User $customer,
        Merchant $merchant,
        BonusValue $amount,
        ?string $receiptNo = null,
        ?int $branchId = null,
        ?int $cashierId = null,
        array $meta = [],
    ): LedgerEntry {
        if ($amount->isZero()) {
            throw new RuntimeException('Earn amount sıfır ola bilməz.');
        }

        if (! $merchant->isActive()) {
            throw new RuntimeException('Merchant aktiv deyil — earn qadağandır.');
        }

        return DB::transaction(function () use ($customer, $merchant, $amount, $receiptNo, $branchId, $cashierId, $meta) {
            $bucket = $this->lockOrCreateBucket($customer->id, $merchant->id);

            $bucket->balance        += $amount->amount;
            $bucket->earned_total   += $amount->amount;
            $bucket->last_activity_at = now();
            $bucket->save();

            return $this->writeEntry(
                type: LedgerEntryType::Earn,
                customer: $customer,
                merchant: $merchant,
                amount: $amount,
                balanceAfter: $bucket->balance,
                ref: $receiptNo,
                branchId: $branchId,
                cashierId: $cashierId,
                meta: $meta,
            );
        });
    }

    /**
     * Bucket-dən bonus xərclə. Yalnız öz merchant-ında mümkündür.
     */
    public function redeem(
        User $customer,
        Merchant $merchant,
        BonusValue $amount,
        ?string $receiptNo = null,
        ?int $branchId = null,
        ?int $cashierId = null,
        array $meta = [],
    ): LedgerEntry {
        if ($amount->isZero()) {
            throw new RuntimeException('Redeem amount sıfır ola bilməz.');
        }

        return DB::transaction(function () use ($customer, $merchant, $amount, $receiptNo, $branchId, $cashierId, $meta) {
            $bucket = $this->lockBucket($customer->id, $merchant->id);

            if (! $bucket->canSpend($amount)) {
                throw new RuntimeException(sprintf(
                    'Kifayət qədər bonus yoxdur. Mövcud: %s, tələb olunur: %s',
                    (new BonusValue($bucket->balance))->format(),
                    $amount->format(),
                ));
            }

            $bucket->balance         -= $amount->amount;
            $bucket->redeemed_total  += $amount->amount;
            $bucket->last_activity_at = now();
            $bucket->save();

            return $this->writeEntry(
                type: LedgerEntryType::Redeem,
                customer: $customer,
                merchant: $merchant,
                amount: $amount,
                balanceAfter: $bucket->balance,
                ref: $receiptNo,
                branchId: $branchId,
                cashierId: $cashierId,
                meta: $meta,
            );
        });
    }

    /**
     * Refund — original earn-i tam və ya qismən geri qaytarır.
     */
    public function refund(LedgerEntry $original, ?BonusValue $partial = null, ?int $cashierId = null, ?string $reason = null): LedgerEntry
    {
        if ($original->type !== LedgerEntryType::Earn) {
            throw new RuntimeException('Yalnız Earn tipli entry-lər refund oluna bilər.');
        }

        $amount = $partial ?? new BonusValue($original->amount);

        if ($amount->amount > $original->amount) {
            throw new RuntimeException('Refund məbləği orijinaldan böyük ola bilməz.');
        }

        return DB::transaction(function () use ($original, $amount, $cashierId, $reason) {
            $bucket = $this->lockBucket($original->user_id, $original->merchant_id);

            // Refund debit-dir: balansı azaldır
            if ($bucket->balance < $amount->amount) {
                // Müştəri artıq xərcləyibsə də ledger təmiz qalmalıdır — mənfi balansa düşmə.
                throw new RuntimeException(
                    'Müştəri artıq bu bonusu xərcləyib, refund mümkün deyil. Manual adjustment lazımdır.'
                );
            }

            $bucket->balance        -= $amount->amount;
            $bucket->last_activity_at = now();
            $bucket->save();

            $meta = ['original_uid' => $original->uid];
            if ($reason !== null && $reason !== '') {
                $meta['reason'] = $reason;
            }

            return $this->writeEntry(
                type: LedgerEntryType::Refund,
                customer: $original->user,
                merchant: $original->merchant,
                amount: $amount,
                balanceAfter: $bucket->balance,
                ref: $original->ref,
                branchId: $original->branch_id,
                cashierId: $cashierId,
                meta: $meta,
                reversesId: $original->id,
            );
        });
    }

    /**
     * Admin tərəfindən manual adjustment.
     */
    public function adjust(
        User $customer,
        Merchant $merchant,
        BonusValue $amount,
        string $reason,
        int $adminId,
    ): LedgerEntry {
        return DB::transaction(function () use ($customer, $merchant, $amount, $reason, $adminId) {
            $bucket = $this->lockOrCreateBucket($customer->id, $merchant->id);

            $bucket->balance        += $amount->amount;
            $bucket->last_activity_at = now();
            $bucket->save();

            return $this->writeEntry(
                type: LedgerEntryType::Adjustment,
                customer: $customer,
                merchant: $merchant,
                amount: $amount,
                balanceAfter: $bucket->balance,
                cashierId: $adminId,
                meta: ['reason' => $reason, 'admin_id' => $adminId],
            );
        });
    }

    // -- Internal helpers --

    private function lockBucket(int $userId, int $merchantId): Bucket
    {
        $bucket = Bucket::where('user_id', $userId)
            ->where('merchant_id', $merchantId)
            ->lockForUpdate()
            ->first();

        if (! $bucket) {
            throw new RuntimeException('Bu merchant üçün bucket yoxdur.');
        }

        return $bucket;
    }

    /**
     * Race-safe bucket lookup-or-create.
     *
     * Köhnə implementation: `lockForUpdate()->first()` qaytaranda null gəlirsə `create()` çağırırdı.
     * Lakin SQL semantikasına görə MÖVCUD OLMAYAN sətirdə lock tutulmur — iki paralel
     * "first earn" sorğusu eyni anda null görür, hər ikisi insert edir, ikincisi
     * unique(`user_id`,`merchant_id`) constraint-i pozur.
     *
     * Yeni davranış:
     *   1) firstOrCreate ilə sətrin mövcudluğunu təmin et (atomic deyilsə də unique
     *      violation-ı tutub retry edirik — limit 3).
     *   2) Yalnız BUNDAN SONRA `lockForUpdate()` ilə sətri kilidlə.
     *
     * Beləliklə eyni (user_id, merchant_id) cütü üçün heç bir halda iki bucket yaranmır.
     */
    private function lockOrCreateBucket(int $userId, int $merchantId): Bucket
    {
        $attempts = 0;
        $maxAttempts = 3;

        while (true) {
            try {
                Bucket::firstOrCreate(
                    ['user_id' => $userId, 'merchant_id' => $merchantId],
                    [
                        'balance'        => 0,
                        'earned_total'   => 0,
                        'redeemed_total' => 0,
                        'expired_total'  => 0,
                    ],
                );
                break;
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Paralel insert qalib gəlib — sətir artıq var. Növbəti turda firstOrCreate
                // SELECT-i ilə tutulacaq.
                if (++$attempts >= $maxAttempts) {
                    throw $e;
                }
                usleep(10_000); // 10ms backoff
            } catch (\Illuminate\Database\QueryException $e) {
                // Deadlock (SQLSTATE 40001 / MySQL 1213) və ya lock timeout — retry et.
                $sqlState = $e->getCode();
                if (! in_array($sqlState, ['40001', '40P01'], strict: true) || ++$attempts >= $maxAttempts) {
                    throw $e;
                }
                usleep(10_000);
            }
        }

        // Artıq sətir mütləq mövcuddur — atomic update üçün kilidlə.
        $bucket = Bucket::where('user_id', $userId)
            ->where('merchant_id', $merchantId)
            ->lockForUpdate()
            ->first();

        if (! $bucket) {
            // Praktiki olaraq baş verə bilməz, lakin müdafiə xətti.
            throw new RuntimeException('Bucket firstOrCreate-dən sonra tapılmadı.');
        }

        return $bucket;
    }

    private function writeEntry(
        LedgerEntryType $type,
        User $customer,
        Merchant $merchant,
        BonusValue $amount,
        int $balanceAfter,
        ?string $ref = null,
        ?int $branchId = null,
        ?int $cashierId = null,
        array $meta = [],
        ?int $reversesId = null,
    ): LedgerEntry {
        // Hash chain: əvvəlki entry-nin hash-ini götür.
        //
        // PERF: əvvəllər `LedgerEntry::orderByDesc('id')->lockForUpdate()->first()`
        // çağırırdıq — bu, InnoDB-də `ledger_entries` cədvəlində supremum gap-lock
        // yaradır və bütün paralel ledger yazılarını qlobal serialize edir.
        //
        // İndi tək sətirli `ledger_chain_tail` cədvəlində (id=1) X-lock alırıq.
        // Hash chain korrektliyi eynidir (yazılar yenə də linear sıralanır), lakin
        // kilid kiçik, named row üzərindədir — ledger_entries-də gap-lock yaranmır,
        // SELECT/audit sorğuları yazılarla bloklaşmır.
        //
        // Bu çağırı əsalinən LedgerService-dəki hər metod artıq DB::transaction içindir.
        $tail = DB::table('ledger_chain_tail')
            ->where('id', 1)
            ->lockForUpdate()
            ->first();

        if ($tail === null) {
            throw new RuntimeException(
                'ledger_chain_tail sətri tapılmadı — migration 0001_01_01_000500 run olunmalıdır.'
            );
        }

        $prevHash = $tail->last_hash;

        // created_at-i əvvəlcədən təsbit edirik ki, hash-də işlədiyən dəyər DB-yə də yazılsın.
        // Saniyə dəqiqliyi — SQLite/MySQL default timestamp formati ilə uyğundur,
        // re-read zamanı mikrosaniyə itməsinə görə hash uyğunsuzluğundan qaçırıq.
        $now = now()->startOfSecond();
        $uid = 'le_' . Str::ulid()->toBase32();

        $entryHash = self::computeHash(
            prevHash:   $prevHash,
            uid:        $uid,
            userId:     $customer->id,
            merchantId: $merchant->id,
            type:       $type,
            amount:     $amount->amount,
            ref:        $ref,
            createdAt:  $now,
        );

        $entry = LedgerEntry::create([
            'uid'           => $uid,
            'user_id'       => $customer->id,
            'merchant_id'   => $merchant->id,
            'branch_id'     => $branchId,
            'cashier_id'    => $cashierId,
            'type'          => $type,
            'amount'        => $amount->amount,
            'balance_after' => $balanceAfter,
            'ref'           => $ref,
            'reverses_id'   => $reversesId,
            'meta'          => $meta,
            'prev_hash'     => $prevHash,
            'entry_hash'    => $entryHash,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $this->advanceChainTail($entry);

        return $entry;
    }

    /**
     * Tail pointer-i yenilə — yalnız writeEntry-dən sonra çağırılır, tail row
     * artıq writeEntry içində kilidlənib (eyni transaction).
     */
    private function advanceChainTail(LedgerEntry $entry): void
    {
        DB::table('ledger_chain_tail')
            ->where('id', 1)
            ->update([
                'last_entry_id' => $entry->id,
                'last_hash'     => $entry->entry_hash,
                'updated_at'    => now(),
            ]);
    }

    /**
     * Determınıstık entry hash: bir entry-ni deyışdirmək bir balıq-effekti ilə bütün
     * sonrakı hash-ləri pozur. Beləliklə açıq (lakin təsdıq oluna bilən) audit chain qurulur.
     *
     * NOT: bu metod pərdə arkı deyıl — verifyChain() və testlər üçın çağırıla bılnır.
     */
    public static function computeHash(
        ?string $prevHash,
        string $uid,
        int $userId,
        int $merchantId,
        LedgerEntryType|string $type,
        int $amount,
        ?string $ref,
        \DateTimeInterface|string $createdAt,
    ): string {
        $typeStr = $type instanceof LedgerEntryType ? $type->value : $type;
        $createdAtStr = $createdAt instanceof \DateTimeInterface
            ? $createdAt->format('Y-m-d H:i:s')
            : (string) $createdAt;

        $payload = implode('|', [
            $prevHash ?? '',
            $uid,
            (string) $userId,
            (string) $merchantId,
            $typeStr,
            (string) $amount,
            $ref ?? '',
            $createdAtStr,
        ]);

        return hash('sha256', $payload);
    }

    /**
     * Hash chain doqrulaması. Bütün ledger-i ardicilligıla oxuyub hər entry-nin
     * prev_hash və entry_hash sahələrinin hələ də düzgün olduğunu yoxlayır.
     *
     * @return array{valid: bool, broken_ids: array<int, int>, checked: int}
     */
    public function verifyChain(): array
    {
        $prevHash = null;
        $broken   = [];
        $checked  = 0;

        foreach (LedgerEntry::orderBy('id')->cursor() as $entry) {
            $expected = self::computeHash(
                prevHash:   $prevHash,
                uid:        $entry->uid,
                userId:     (int) $entry->user_id,
                merchantId: (int) $entry->merchant_id,
                type:       $entry->type,
                amount:     (int) $entry->amount,
                ref:        $entry->ref,
                createdAt:  $entry->created_at,
            );

            if ($entry->prev_hash !== $prevHash || $entry->entry_hash !== $expected) {
                $broken[] = (int) $entry->id;
            }

            $prevHash = $entry->entry_hash;
            $checked++;
        }

        return ['valid' => empty($broken), 'broken_ids' => $broken, 'checked' => $checked];
    }

    /**
     * Məhsul qaytarıldıqda satışı tam ləğv edir (RETURN_REVERSAL / bonus_reversal).
     *
     * ƏSAS QAYDA:
     *  - Orijinal `Earn` ledger entry-si FİZİKİ silinmir — ledger append-only qalır.
     *  - Earn hissəsi üçün YENİ `Reversal` (debit) entry yazılır — istifadəçinin
     *    available balansından çıxılır. Entry `reverses_id` ilə orijinala bağlanır
     *    və meta-da return qəbzi/orijinal qəbz/səbəb saxlanır.
     *  - Redeem hissəsi (varsa) `Adjustment` credit kimi geri qaytarılır
     *    (`Reversal` enum debit-only olduğu üçün; meta-da `return_reversal:` marker var).
     *
     * Reverse YALNIZ məhsul qaytarıldıqda mümkündür — `$returnReceiptNo` məcburidir
     * və qaytarma qəbzinin nömrəsidir (POS-dan və ya admin-dən gəlir).
     *
     * Idempotency: təkrar çağırış tx.status='reversed' görür və RuntimeException
     * atır; heç bir yeni ledger entry yaranmır.
     *
     * @param  Transaction $tx              Reverse olunacaq satış.
     * @param  int         $cashierId       Əməliyyatı icra edən user id (cashier və ya admin).
     * @param  string      $returnReceiptNo Məhsul qaytarma qəbzi nömrəsi (audit üçün məcburi).
     * @param  string|null $reason          Audit üçün insan-oxunaqlı səbəb (max 500 simvol).
     * @return array<int, LedgerEntry>      Yeni yaradılan revers entry-ləri.
     */
    public function reverseTransaction(
        Transaction $tx,
        int $cashierId,
        string $returnReceiptNo,
        ?string $reason = null,
    ): array {
        if (trim($returnReceiptNo) === '') {
            throw new RuntimeException('Reverse üçün məhsul qaytarma qəbzi nömrəsi (return_receipt_no) məcburidir.');
        }

        return DB::transaction(function () use ($tx, $cashierId, $returnReceiptNo, $reason) {
            // Lock + re-fetch fresh status.
            $tx = Transaction::lockForUpdate()->findOrFail($tx->id);

            if ($tx->status === 'reversed') {
                throw new RuntimeException('Transaction artıq reverse olunub: ' . $tx->receipt_no);
            }

            $reverseEntries = [];

            // 1) Earn-i Reversal (debit) ilə geri qaytar — orijinal entry SİLİNMİR.
            if ((int) $tx->earned_amount > 0) {
                $earnEntry = LedgerEntry::where('merchant_id', $tx->merchant_id)
                    ->where('ref', $tx->receipt_no)
                    ->where('type', LedgerEntryType::Earn)
                    ->first();

                if ($earnEntry) {
                    $reverseEntries[] = $this->writeReversalDebit(
                        original:        $earnEntry,
                        cashierId:       $cashierId,
                        returnReceiptNo: $returnReceiptNo,
                        reason:          $reason,
                    );
                }
            }

            // 2) Redeem-i bucket-ə geri qaytar — Adjustment credit
            //    (Reversal enum debit-only olduğu üçün; meta marker ilə tanınır).
            if ((int) $tx->redeemed_amount > 0) {
                $customer = User::findOrFail($tx->user_id);
                $merchant = Merchant::findOrFail($tx->merchant_id);

                $adjReason = 'return_reversal:tx_' . $tx->id
                    . ':receipt_' . $tx->receipt_no
                    . ':return_' . $returnReceiptNo
                    . ($reason !== null && $reason !== '' ? ' | ' . $reason : '');

                $reverseEntries[] = $this->adjust(
                    customer: $customer,
                    merchant: $merchant,
                    amount:   new BonusValue((int) $tx->redeemed_amount),
                    reason:   $adjReason,
                    adminId:  $cashierId,
                );
            }

            // 3) Transaction-ı reversed statusuna keçir.
            $tx->status = 'reversed';
            $tx->save();

            return $reverseEntries;
        });
    }

    /**
     * Earn entry-sinə qarşı Reversal (bonus_reversal) tipli DEBIT yazır.
     *
     *  - Orijinal entry toxunulmur (append-only); yeni entry-nin `reverses_id`-i ona işarə edir.
     *  - `ref` qaytarma qəbzinin nömrəsidir — `unique(merchant_id, ref, type)` sayəsində
     *    eyni qaytarma qəbzi ilə ikiqat Reversal yazıla bilməz (SQL səviyyəsində idempotency).
     *  - Müştəri bonusu artıq xərcləyibsə balans çatmır → RuntimeException;
     *    bu halda admin manual `adjust()` etməlidir (qayda).
     */
    private function writeReversalDebit(
        LedgerEntry $original,
        int $cashierId,
        string $returnReceiptNo,
        ?string $reason,
    ): LedgerEntry {
        $bucket = $this->lockBucket($original->user_id, $original->merchant_id);
        $amount = new BonusValue((int) $original->amount);

        if ($bucket->balance < $amount->amount) {
            throw new RuntimeException(
                'Müştəri artıq bu bonusu xərcləyib, return reversal mümkün deyil. Manual adjustment lazımdır.'
            );
        }

        $bucket->balance         -= $amount->amount;
        $bucket->last_activity_at = now();
        $bucket->save();

        $meta = [
            'original_uid'        => $original->uid,
            'original_receipt_no' => $original->ref,
            'return_receipt_no'   => $returnReceiptNo,
        ];
        if ($reason !== null && $reason !== '') {
            $meta['reason'] = $reason;
        }

        return $this->writeEntry(
            type:         LedgerEntryType::Reversal,
            customer:     $original->user,
            merchant:     $original->merchant,
            amount:       $amount,
            balanceAfter: $bucket->balance,
            ref:          $returnReceiptNo,
            branchId:     $original->branch_id,
            cashierId:    $cashierId,
            meta:         $meta,
            reversesId:   $original->id,
        );
    }
}
