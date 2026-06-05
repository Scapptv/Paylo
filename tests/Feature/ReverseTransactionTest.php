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
| LedgerService::reverseTransaction — earn + redeem atomic geri çevirilməsi.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->customer = User::factory()->create();
    $this->cashier  = User::factory()->create();
});

it('reverses an earn-only transaction and restores the bucket to zero', function () {
    // Tx üçün ledger-də earn yarat (manual — controller yolu olmadan)
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount:   new BonusValue(500),
        receiptNo: 'r_rev_1',
    );

    $tx = Transaction::create([
        'receipt_no'      => 'r_rev_1',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 10000,
        'earned_amount'   => 500,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $reversed = $this->ledger->reverseTransaction($tx, $this->cashier->id, returnReceiptNo: 'rr_1');

    expect($reversed)->toHaveCount(1);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->first();
    expect($bucket->balance)->toBe(0);

    $tx->refresh();
    expect($tx->status)->toBe(TransactionStatus::Reversed);

    // Reversal (bonus_reversal) tipli yeni entry yarandı — ref qaytarma qəbzidir.
    $reversalCount = LedgerEntry::where('merchant_id', $this->merchant->id)
        ->where('ref', 'rr_1')
        ->where('type', LedgerEntryType::Reversal)
        ->count();
    expect($reversalCount)->toBe(1);

    // Orijinal Earn entry-si SILİNMƏYİB — ledger append-only.
    $earnStillThere = LedgerEntry::where('merchant_id', $this->merchant->id)
        ->where('ref', 'r_rev_1')
        ->where('type', LedgerEntryType::Earn)
        ->exists();
    expect($earnStillThere)->toBeTrue();
});

it('reverses an earn + redeem transaction fully (debit earn, credit redeem)', function () {
    // Pre-existing bucket balansı: 800
    Bucket::create([
        'user_id' => $this->customer->id,
        'merchant_id' => $this->merchant->id,
        'balance' => 800, 'earned_total' => 800,
        'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    // Satış: redeem 300, earn 100 → bucket = 800 - 300 + 100 = 600
    $this->ledger->redeem($this->customer, $this->merchant, new BonusValue(300), receiptNo: 'r_rev_2');
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(100), receiptNo: 'r_rev_2');

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->first();
    expect($bucket->balance)->toBe(600);

    $tx = Transaction::create([
        'receipt_no'      => 'r_rev_2',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 300,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $reversed = $this->ledger->reverseTransaction($tx, $this->cashier->id, returnReceiptNo: 'rr_2');

    // İki reverse entry: 1 Reversal (earn üçün debit) + 1 Adjustment (redeem credit-back).
    expect($reversed)->toHaveCount(2);

    $bucket->refresh();
    // Reverse: -100 (refund earn) + 300 (adjust credit) = +200 → 600 + 200 = 800 (original)
    expect($bucket->balance)->toBe(800);

    $tx->refresh();
    expect($tx->status)->toBe(TransactionStatus::Reversed);
});

it('is idempotent — second reverseTransaction throws and creates no new entries', function () {
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(200), receiptNo: 'r_rev_3');

    $tx = Transaction::create([
        'receipt_no'      => 'r_rev_3',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 4000,
        'earned_amount'   => 200,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $this->ledger->reverseTransaction($tx, $this->cashier->id, returnReceiptNo: 'rr_3');

    $entriesBefore = LedgerEntry::count();

    expect(fn () => $this->ledger->reverseTransaction($tx, $this->cashier->id, returnReceiptNo: 'rr_3b'))
        ->toThrow(RuntimeException::class, 'artıq reverse');

    $entriesAfter = LedgerEntry::count();
    expect($entriesAfter)->toBe($entriesBefore);
});
