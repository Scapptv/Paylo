<?php

declare(strict_types=1);

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\TransactionStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST /pos/sale/{receiptNo}/reverse — merchant_owner / merchant_staff / admin endpoint-i.
| Audit P-4: kassir bu endpoint-i çağıra bilməz (vəzifə bölgüsü, fraud qarşısı).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]); // 2%
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);

    $this->merchant = Merchant::factory()->create([
        'status'   => 'active',
        'category' => 'grocery',
        'tier'     => 'standard',
    ]);

    $this->cashier = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    // Reverse səlahiyyəti olan istifadəçi (merchant_staff). Satışı kassir edir,
    // amma reverse-i yalnız staff/owner/admin çağıra bilər.
    $this->staff = User::factory()->create([
        'role'        => UserRole::MerchantStaff,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    $this->customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'merchant_id' => null,
    ]);

    // Helper: önə bir satış yarat, qaytarsın tx-i.
    $this->makeSale = function (string $receipt, int $cents = 5000): Transaction {
        $this->actingAs($this->cashier)->postJson('/pos/sale', [
            'customer_id'       => $this->customer->id,
            'sale_amount_cents' => $cents,
            'receipt_no'        => $receipt,
            'use_bonus'         => false,
        ])->assertOk();

        return Transaction::where('merchant_id', $this->merchant->id)
            ->where('receipt_no', $receipt)
            ->firstOrFail();
    };
});

it('reverses a completed sale and zeroes the bucket', function () {
    $tx = ($this->makeSale)('r_rev_ep_1');

    $bucketBefore = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->firstOrFail();
    expect($bucketBefore->balance)->toBe(100); // 2% × 5000

    $response = $this->actingAs($this->staff)
        ->postJson('/pos/sale/r_rev_ep_1/reverse', [
            'return_receipt_no' => 'rr_ep_1',
            'reason'            => 'müştəri imtina etdi',
        ]);

    $response->assertOk()
        ->assertJson([
            'transaction_id'   => $tx->id,
            'receipt_no'       => 'r_rev_ep_1',
            'status'           => 'reversed',
            'already_reversed' => false,
        ]);

    expect($response->json('reverse_entries'))->toHaveCount(1);
    expect($response->json('reverse_entries.0.type'))->toBe('reversal');

    $tx->refresh();
    expect($tx->status)->toBe(TransactionStatus::Reversed);

    $bucketBefore->refresh();
    expect($bucketBefore->balance)->toBe(0);

    // Orijinal Earn entry-si SILİNMƏYİB — append-only ledger.
    expect(LedgerEntry::where('merchant_id', $this->merchant->id)
        ->where('ref', 'r_rev_ep_1')
        ->where('type', LedgerEntryType::Earn)
        ->exists()
    )->toBeTrue();
});

it('is idempotent — second reverse returns already_reversed without new entries', function () {
    ($this->makeSale)('r_rev_ep_2');

    $first = $this->actingAs($this->staff)
        ->postJson('/pos/sale/r_rev_ep_2/reverse', ['return_receipt_no' => 'rr_ep_2'])
        ->assertOk();
    expect($first->json('already_reversed'))->toBeFalse();

    $entriesAfterFirst = LedgerEntry::count();

    $second = $this->actingAs($this->staff)
        ->postJson('/pos/sale/r_rev_ep_2/reverse', ['return_receipt_no' => 'rr_ep_2'])
        ->assertOk();
    expect($second->json('already_reversed'))->toBeTrue();
    expect($second->json('reverse_entries'))->toBe([]);

    expect(LedgerEntry::count())->toBe($entriesAfterFirst);
});

it('returns 404 for a receipt that belongs to another merchant (no enumeration)', function () {
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);
    $otherCashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $otherMerchant->id,
        'is_active'   => true,
    ]);

    // Başqa mağazada satış yarat.
    $this->actingAs($otherCashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 3000,
        'receipt_no'        => 'r_other_merchant',
        'use_bonus'         => false,
    ])->assertOk();

    // Cari merchant-ın staff-ı başqa merchant-ın qəbzini reverse etməyə çalışır.
    $this->actingAs($this->staff)
        ->postJson('/pos/sale/r_other_merchant/reverse', ['return_receipt_no' => 'rr_other'])
        ->assertNotFound();

    // Başqa merchant-ın tx-i toxunulmaz qalır.
    expect(Transaction::where('receipt_no', 'r_other_merchant')->value('status'))
        ->toBe(TransactionStatus::Completed);
});

it('returns 404 for an unknown receipt', function () {
    $this->actingAs($this->staff)
        ->postJson('/pos/sale/does_not_exist/reverse', ['return_receipt_no' => 'rr_x'])
        ->assertNotFound();
});

it('returns 422 when customer has already spent the bonus (refund would underflow)', function () {
    // 1) Satış yarat → bucket balance = 100.
    ($this->makeSale)('r_rev_ep_3', cents: 5000);

    // 2) Müştəri başqa bir satışda balansı xərcləyir → bucket = 0.
    $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 2000,
        'receipt_no'        => 'r_spend',
        'use_bonus'         => true,
        'redeem_cents'      => 100,
    ])->assertOk();

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->firstOrFail();
    // 100 earn (1-ci satış) - 100 redeem (2-ci satış) + 40 earn (2-ci satış) = 40
    expect($bucket->balance)->toBe(40);

    // 3) İlk satışı reverse etmək istəyir → refund 100 lazımdır, balans yalnız 40 → 422.
    $this->actingAs($this->staff)
        ->postJson('/pos/sale/r_rev_ep_3/reverse', ['return_receipt_no' => 'rr_ep_3'])
        ->assertStatus(422)
        ->assertJsonStructure(['status', 'message']);

    expect(Transaction::where('receipt_no', 'r_rev_ep_3')->value('status'))
        ->toBe(TransactionStatus::Completed);
});

it('rejects reason longer than 500 chars', function () {
    ($this->makeSale)('r_rev_ep_4');

    $this->actingAs($this->staff)
        ->postJson('/pos/sale/r_rev_ep_4/reverse', [
            'return_receipt_no' => 'rr_ep_4',
            'reason'            => str_repeat('a', 501),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('requires return_receipt_no', function () {
    ($this->makeSale)('r_rev_ep_5');

    $this->actingAs($this->staff)
        ->postJson('/pos/sale/r_rev_ep_5/reverse', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['return_receipt_no']);

    expect(Transaction::where('receipt_no', 'r_rev_ep_5')->value('status'))->toBe(TransactionStatus::Completed);
});

it('blocks unauthenticated requests', function () {
    $this->postJson('/pos/sale/r_anon/reverse', ['return_receipt_no' => 'rr_anon'])
        ->assertStatus(401);
});

it('blocks non-authorised roles (e.g. customer)', function () {
    $this->actingAs($this->customer)
        ->postJson('/pos/sale/r_role/reverse', ['return_receipt_no' => 'rr_role'])
        ->assertStatus(403);
});

// Audit P-4: kassir reverse səlahiyyətindən məhrumdur — fraud qarşısı.
it('blocks cashier role from reversing (P-4 segregation of duties)', function () {
    ($this->makeSale)('r_rev_ep_6');

    $this->actingAs($this->cashier)
        ->postJson('/pos/sale/r_rev_ep_6/reverse', ['return_receipt_no' => 'rr_ep_6'])
        ->assertStatus(403);

    // Tx toxunulmaz qalır.
    expect(Transaction::where('receipt_no', 'r_rev_ep_6')->value('status'))->toBe(TransactionStatus::Completed);
});

// POS terminalı (auto-login cihaz) eyni səbəbdən bloklanır.
it('blocks pos_terminal role from reversing (P-4)', function () {
    ($this->makeSale)('r_rev_ep_7');

    $posTerminal = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    $this->actingAs($posTerminal)
        ->postJson('/pos/sale/r_rev_ep_7/reverse', ['return_receipt_no' => 'rr_ep_7'])
        ->assertStatus(403);
});

// merchant_owner reverse edə bilməlidir.
it('allows merchant_owner to reverse', function () {
    ($this->makeSale)('r_rev_ep_8');

    $owner = User::factory()->create([
        'role'        => UserRole::MerchantOwner,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    $this->actingAs($owner)
        ->postJson('/pos/sale/r_rev_ep_8/reverse', ['return_receipt_no' => 'rr_ep_8'])
        ->assertOk();

    expect(Transaction::where('receipt_no', 'r_rev_ep_8')->value('status'))->toBe(TransactionStatus::Reversed);
});
