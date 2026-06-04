<?php

declare(strict_types=1);

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\TransactionStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Branch;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);

    $this->merchant = Merchant::factory()->create([
        'status'   => 'active',
        'category' => 'grocery',
        'tier'     => 'standard',
    ]);

    $this->posUser = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    $this->customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'merchant_id' => null,
        'is_active'   => true,
    ]);

    Sanctum::actingAs($this->posUser, abilities: ['pos:write', 'pos:reverse']);
});

/*
|--------------------------------------------------------------------------
| Customer lookup
|--------------------------------------------------------------------------
*/

it('returns ok + customer + bucket when looking up a real static QR', function () {
    $response = $this->postJson('/api/v1/pos/customer/lookup', [
        'qr' => $this->customer->customer_qr,
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'customer' => ['id', 'name'],
            'bucket'   => ['balance', 'earned_total', 'redeemed_total'],
        ]);

    expect($response->json('status'))->toBe('ok');
    expect($response->json('customer.id'))->toBe($this->customer->id);
    expect($response->json('bucket.balance'))->toBe(0);
});

it('returns uniform not_found shape for an unknown QR (no enumeration)', function () {
    $response = $this->postJson('/api/v1/pos/customer/lookup', [
        'qr' => 'qr_doesnotexist',
    ]);

    $response->assertOk()
        ->assertJson([
            'status'   => 'not_found',
            'customer' => null,
            'bucket'   => null,
        ]);
});

it('does not leak customer_qr in the lookup response', function () {
    $response = $this->postJson('/api/v1/pos/customer/lookup', [
        'qr' => $this->customer->customer_qr,
    ]);

    // Audit P-2: rotating QR sisteminin mövcudluğu səbəbi — static QR cashier-ə qaytarılmamalı.
    expect($response->json('customer'))
        ->not->toHaveKey('customer_qr')
        ->not->toHaveKey('email')
        ->not->toHaveKey('phone');
});

/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
*/

it('previews earn for a 50 AZN sale without redemption', function () {
    $response = $this->postJson('/api/v1/pos/sale/preview', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'use_bonus'         => false,
    ]);

    $response->assertOk()->assertJson([
        'sale_amount'       => 5000,
        'earn_amount'       => 100, // 2% × 5000 = 100 qəpik
        'redeem_amount'     => 0,
        'final_to_pay'      => 5000,
        'projected_balance' => 100,
    ]);
});

/*
|--------------------------------------------------------------------------
| Sale completion
|--------------------------------------------------------------------------
*/

it('completes a sale and credits exactly the previewed earn amount', function () {
    $response = $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'api-r-001',
        'use_bonus'         => false,
    ]);

    $response->assertOk()->assertJson([
        'receipt_no' => 'api-r-001',
        'status'     => 'completed',
        'idempotent' => false,
    ]);

    expect(Transaction::where('merchant_id', $this->merchant->id)
        ->where('receipt_no', 'api-r-001')->count())->toBe(1);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->first();
    expect($bucket->balance)->toBe(100);
    expect($bucket->earned_total)->toBe(100);
});

it('is domain-idempotent on receipt_no — same receipt twice returns same tx, no duplicate ledger', function () {
    $payload = [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'api-r-retry',
        'use_bonus'         => false,
    ];

    $first  = $this->postJson('/api/v1/pos/sale', $payload);
    $second = $this->postJson('/api/v1/pos/sale', $payload);

    $first->assertOk();
    $second->assertOk()->assertJson(['idempotent' => true]);

    expect($first->json('transaction_id'))->toBe($second->json('transaction_id'));

    expect(LedgerEntry::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->where('type', LedgerEntryType::Earn)
        ->count())->toBe(1);
});

it('rejects branch_id from another merchant (cross-merchant scope protection)', function () {
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);
    $otherBranch   = Branch::factory()->create(['merchant_id' => $otherMerchant->id]);

    $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'api-r-cross',
        'branch_id'         => $otherBranch->id,
        'use_bonus'         => false,
    ])->assertStatus(422)->assertJsonValidationErrors(['branch_id']);
});

it('rejects customer_id whose role is not Customer', function () {
    $merchantOwner = User::factory()->create([
        'role'        => UserRole::MerchantOwner,
        'merchant_id' => $this->merchant->id,
    ]);

    $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $merchantOwner->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'api-r-bad-role',
        'use_bonus'         => false,
    ])->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
});

it('rejects legacy float field sale_amount with a clear error', function () {
    $this->postJson('/api/v1/pos/sale', [
        'customer_id' => $this->customer->id,
        'sale_amount' => 50.00,
        'receipt_no'  => 'api-r-legacy',
        'use_bonus'   => false,
    ])->assertStatus(422)->assertJsonValidationErrors(['sale_amount']);
});

it('rejects redeem_cents when use_bonus=false (P-8)', function () {
    $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'api-r-no-bonus-but-redeem',
        'use_bonus'         => false,
        'redeem_cents'      => 100,
    ])->assertStatus(422)->assertJsonValidationErrors(['redeem_cents']);
});

/*
|--------------------------------------------------------------------------
| Sale reversal
|--------------------------------------------------------------------------
*/

it('reverses a completed sale and zeroes the bucket', function () {
    $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'api-r-rev-1',
        'use_bonus'         => false,
    ])->assertOk();

    $reverse = $this->postJson('/api/v1/pos/sale/api-r-rev-1/reverse', [
        'return_receipt_no' => 'RET-001',
        'reason'            => 'customer changed mind',
    ]);

    $reverse->assertOk()->assertJsonFragment(['status' => 'reversed']);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->first();
    expect($bucket->balance)->toBe(0);
});

it('returns 404 (not_found) reversing a receipt from another merchant — no enumeration', function () {
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);
    $otherPos      = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $otherMerchant->id,
    ]);
    Sanctum::actingAs($otherPos, abilities: ['pos:write', 'pos:reverse']);

    // Create a sale in other merchant
    Transaction::create([
        'receipt_no'      => 'foreign-rcpt',
        'merchant_id'     => $otherMerchant->id,
        'cashier_id'      => $otherPos->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => TransactionStatus::Completed,
        'occurred_at'     => now(),
    ]);

    // Reverse from a DIFFERENT merchant's POS token
    Sanctum::actingAs($this->posUser, abilities: ['pos:write', 'pos:reverse']);
    $this->postJson('/api/v1/pos/sale/foreign-rcpt/reverse', [
        'return_receipt_no' => 'RET-X',
    ])->assertStatus(404)->assertJsonFragment(['status' => 'not_found']);
});
