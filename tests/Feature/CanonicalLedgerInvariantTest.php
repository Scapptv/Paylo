<?php

declare(strict_types=1);

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\TransactionStatus;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| 2026-06-04 — Kanonik ledger invariantlarının dəqiq yoxlanışı.
|
| Bütün bonus hərəkətləri (earn / redeem / reversal / adjustment / expire)
| üçün KORUNAN invariantlar:
|   I1  balance          = SUM(credits) − SUM(debits)
|   I2  earned_total     = SUM(type=earn)
|   I3  redeemed_total   = SUM(type=redeem)
|   I4  expired_total    = SUM(type=expire)
|   I5  balance >= 0 (heç vaxt mənfi)
|   I6  hash chain bütöv (verifyChain valid)
|   I7  per-merchant izolyasiya
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->customer = User::factory()->create();
    $this->cashier  = User::factory()->create();
});

/**
 * Ledger entry-lərindən gözlənilən bucket dəyərlərini hesabla (reconcile düsturu).
 */
function ledgerExpected(int $userId, int $merchantId): array
{
    $rows = LedgerEntry::where('user_id', $userId)->where('merchant_id', $merchantId)->get();

    $credits = 0;
    $debits = 0;
    $earned = 0;
    $redeemed = 0;
    $expired = 0;

    foreach ($rows as $e) {
        $amt = (int) $e->amount;
        if ($e->type->isCredit()) {
            $credits += $amt;
        } else {
            $debits += $amt;
        }
        if ($e->type === LedgerEntryType::Earn) {
            $earned += $amt;
        }
        if ($e->type === LedgerEntryType::Redeem) {
            $redeemed += $amt;
        }
        if ($e->type === LedgerEntryType::Expire) {
            $expired += $amt;
        }
    }

    return [
        'balance'        => $credits - $debits,
        'earned_total'   => $earned,
        'redeemed_total' => $redeemed,
        'expired_total'  => $expired,
    ];
}

function assertBucketMatchesLedger($test, int $userId, int $merchantId): void
{
    $bucket = Bucket::where('user_id', $userId)->where('merchant_id', $merchantId)->firstOrFail();
    $expected = ledgerExpected($userId, $merchantId);

    expect((int) $bucket->balance)->toBe($expected['balance']);           // I1
    expect((int) $bucket->earned_total)->toBe($expected['earned_total']); // I2
    expect((int) $bucket->redeemed_total)->toBe($expected['redeemed_total']); // I3
    expect((int) $bucket->expired_total)->toBe($expected['expired_total']);   // I4
    expect((int) $bucket->balance)->toBeGreaterThanOrEqual(0);            // I5
}

it('conserves all invariants across earn → redeem → earn → reverse(earn+redeem) → expire', function () {
    $c = $this->customer;
    $m = $this->merchant;

    // earn 1000
    $this->ledger->earn($c, $m, new BonusValue(1000), receiptNo: 'r1');
    // redeem 300
    $this->ledger->redeem($c, $m, new BonusValue(300), receiptNo: 'r2');
    // satış r4: redeem 150 + earn 80 (bonus istifadəli satış)
    $this->ledger->redeem($c, $m, new BonusValue(150), receiptNo: 'r4');
    $this->ledger->earn($c, $m, new BonusValue(80), receiptNo: 'r4');

    assertBucketMatchesLedger($this, $c->id, $m->id);

    // r4-ü reverse et (earn 80 geri alınır, redeem 150 bərpa olunur)
    $tx4 = Transaction::create([
        'receipt_no' => 'r4', 'merchant_id' => $m->id, 'cashier_id' => $this->cashier->id,
        'user_id' => $c->id, 'sale_amount' => 5000, 'earned_amount' => 80,
        'redeemed_amount' => 150, 'status' => 'completed', 'occurred_at' => now(),
    ]);
    $this->ledger->reverseTransaction($tx4, $this->cashier->id, returnReceiptNo: 'rr4');

    assertBucketMatchesLedger($this, $c->id, $m->id);

    // expire 100
    $this->ledger->expire($c, $m, new BonusValue(100));

    assertBucketMatchesLedger($this, $c->id, $m->id);

    // I6 — hash chain bütöv
    $chain = $this->ledger->verifyChain();
    expect($chain['valid'])->toBeTrue();
    expect($chain['broken_ids'])->toBe([]);
});

it('CANON-3: reverses a sale with redeem+earn even when current balance < earn (credit-first ordering)', function () {
    $c = $this->customer;
    $m = $this->merchant;

    // Başlanğıc balans: earn 100
    $this->ledger->earn($c, $m, new BonusValue(100), receiptNo: 'seed');

    // Satış r_X: redeem 80 + earn 50 → balance 100 − 80 + 50 = 70
    $this->ledger->redeem($c, $m, new BonusValue(80), receiptNo: 'r_X');
    $this->ledger->earn($c, $m, new BonusValue(50), receiptNo: 'r_X');

    // Müştəri başqa satışda daha çox xərcləyir: redeem 60 → balance 10
    $this->ledger->redeem($c, $m, new BonusValue(60), receiptNo: 'r_spend');

    $bucket = Bucket::where('user_id', $c->id)->where('merchant_id', $m->id)->firstOrFail();
    expect((int) $bucket->balance)->toBe(10); // balance(10) < earn(50)

    // r_X-i reverse: DÜZGÜN nəticə → redeem 80 bərpa (90), earn 50 geri al (40).
    // Köhnə debit-first sıra `balance(10) >= earn(50)` yoxlayıb səhvən 422 atırdı.
    $txX = Transaction::create([
        'receipt_no' => 'r_X', 'merchant_id' => $m->id, 'cashier_id' => $this->cashier->id,
        'user_id' => $c->id, 'sale_amount' => 5000, 'earned_amount' => 50,
        'redeemed_amount' => 80, 'status' => 'completed', 'occurred_at' => now(),
    ]);

    $entries = $this->ledger->reverseTransaction($txX, $this->cashier->id, returnReceiptNo: 'rr_X');

    expect($entries)->toHaveCount(2); // Adjustment(redeem geri) + Reversal(earn geri)

    $bucket->refresh();
    expect((int) $bucket->balance)->toBe(40); // 10 + 80 − 50

    $txX->refresh();
    expect($txX->status)->toBe(TransactionStatus::Reversed);

    // Invariantlar qorunur + heç vaxt mənfi balans
    assertBucketMatchesLedger($this, $c->id, $m->id);
    expect($this->ledger->verifyChain()['valid'])->toBeTrue();
});

it('still rejects an earn-only reverse when balance is genuinely insufficient (no redeem to restore)', function () {
    $c = $this->customer;
    $m = $this->merchant;

    // earn 100, sonra başqa yerdə 70 xərclə → balance 30
    $this->ledger->earn($c, $m, new BonusValue(100), receiptNo: 'r_earn_only');
    $this->ledger->redeem($c, $m, new BonusValue(70), receiptNo: 'r_other');

    // r_earn_only-ni reverse: earn 100 geri almaq lazımdır, redeem 0 → bərpa yox.
    // balance(30) + redeem(0) < earn(100) → düzgün şəkildə rədd edilir.
    $tx = Transaction::create([
        'receipt_no' => 'r_earn_only', 'merchant_id' => $m->id, 'cashier_id' => $this->cashier->id,
        'user_id' => $c->id, 'sale_amount' => 5000, 'earned_amount' => 100,
        'redeemed_amount' => 0, 'status' => 'completed', 'occurred_at' => now(),
    ]);

    expect(fn () => $this->ledger->reverseTransaction($tx, $this->cashier->id, returnReceiptNo: 'rr_eo'))
        ->toThrow(RuntimeException::class);

    // Balans toxunulmaz qaldı (atomic rollback)
    $bucket = Bucket::where('user_id', $c->id)->where('merchant_id', $m->id)->firstOrFail();
    expect((int) $bucket->balance)->toBe(30);
});

it('I7: per-merchant isolation — operations on merchant A never touch merchant B bucket', function () {
    $c = $this->customer;
    $mA = $this->merchant;
    $mB = Merchant::factory()->create(['status' => 'active']);

    $this->ledger->earn($c, $mA, new BonusValue(500), receiptNo: 'a1');
    $this->ledger->earn($c, $mB, new BonusValue(700), receiptNo: 'b1');
    $this->ledger->redeem($c, $mA, new BonusValue(200), receiptNo: 'a2');
    $this->ledger->expire($c, $mB, new BonusValue(100));

    $bucketA = Bucket::where('user_id', $c->id)->where('merchant_id', $mA->id)->firstOrFail();
    $bucketB = Bucket::where('user_id', $c->id)->where('merchant_id', $mB->id)->firstOrFail();

    expect((int) $bucketA->balance)->toBe(300); // 500 − 200
    expect((int) $bucketB->balance)->toBe(600); // 700 − 100

    assertBucketMatchesLedger($this, $c->id, $mA->id);
    assertBucketMatchesLedger($this, $c->id, $mB->id);
});
