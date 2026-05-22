<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', [
        'grocery' => 200, // 2.00%
    ]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', [
        'standard' => 10000,
    ]);

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

    $this->customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'merchant_id' => null,
    ]);
});

it('completes a sale and persists exactly one transaction + one ledger entry', function () {
    $payload = [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000, // 50.00 AZN
        'receipt_no'        => 'r_idempo_1',
        'use_bonus'         => false,
    ];

    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', $payload);

    $response->assertOk()
        ->assertJson([
            'receipt_no' => 'r_idempo_1',
            'status'     => 'completed',
            'idempotent' => false,
        ]);

    expect(Transaction::where('merchant_id', $this->merchant->id)
        ->where('receipt_no', 'r_idempo_1')->count())->toBe(1);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->first();
    expect($bucket->balance)->toBe(100); // 2% × 5000 = 100
});

it('is idempotent on POS retry — same (merchant + receipt_no) twice returns same transaction without duplicate ledger', function () {
    $payload = [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_retry_42',
        'use_bonus'         => false,
    ];

    $first  = $this->actingAs($this->cashier)->postJson('/pos/sale', $payload);
    $second = $this->actingAs($this->cashier)->postJson('/pos/sale', $payload);

    $first->assertOk();
    $second->assertOk()
        ->assertJson(['idempotent' => true, 'receipt_no' => 'r_retry_42', 'status' => 'completed']);

    expect($first->json('transaction_id'))->toBe($second->json('transaction_id'));

    // Bir transaction, bir earn ledger entry — duplicate yoxdur.
    expect(Transaction::where('merchant_id', $this->merchant->id)
        ->where('receipt_no', 'r_retry_42')->count())->toBe(1);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->first();
    expect($bucket->balance)->toBe(100); // ikiqat earn olmayıb
    expect($bucket->earned_total)->toBe(100);
});

it('preview and complete produce identical earn/redeem cents for the same input', function () {
    // Əvvəlcə bucket-də balans yarat ki, redeem mənalı olsun.
    Bucket::create([
        'user_id'     => $this->customer->id,
        'merchant_id' => $this->merchant->id,
        'balance'     => 800,
        'earned_total'=> 800,
    ]);

    $input = [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 7777,
        'use_bonus'         => true,
        'redeem_cents'      => 500,
    ];

    $preview = $this->actingAs($this->cashier)->postJson('/pos/preview', $input);
    $preview->assertOk();

    $complete = $this->actingAs($this->cashier)
        ->postJson('/pos/sale', $input + ['receipt_no' => 'r_parity_1']);
    $complete->assertOk();

    $tx = Transaction::where('receipt_no', 'r_parity_1')->firstOrFail();

    expect($tx->sale_amount)->toBe($preview->json('sale_amount'));
    expect($tx->earned_amount)->toBe($preview->json('earn_amount'));
    expect($tx->redeemed_amount)->toBe($preview->json('redeem_amount'));
});

it('rejects legacy float field sale_amount with a validation error', function () {
    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id' => $this->customer->id,
        'sale_amount' => 50.00, // qadağan
        'receipt_no'  => 'r_float_1',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['sale_amount', 'sale_amount_cents']);
});

it('rejects legacy float field redeem_azn with a validation error', function () {
    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_float_2',
        'use_bonus'         => true,
        'redeem_azn'        => 1.50, // qadağan
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['redeem_azn']);
});

it('rejects float sale_amount_cents (must be integer)', function () {
    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 50.50, // float
        'receipt_no'        => 'r_float_3',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['sale_amount_cents']);
});

// ---------------------------------------------------------------------------
// R2: cross-merchant validation — cashier başqa merchant-ın branch-ini və ya
// Customer rolundan kənar user_id-ni payload-da göndərə bilməz.
// ---------------------------------------------------------------------------

it('rejects branch_id that belongs to another merchant', function () {
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);
    $foreignBranch = \App\Core\Models\Branch::factory()->create([
        'merchant_id' => $otherMerchant->id,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_cross_branch_1',
        'branch_id'         => $foreignBranch->id,
        'use_bonus'         => false,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['branch_id']);

    // Heç bir transaction yazılmamalıdır.
    expect(Transaction::where('receipt_no', 'r_cross_branch_1')->count())->toBe(0);
});

it('accepts branch_id that belongs to the current merchant', function () {
    $ownBranch = \App\Core\Models\Branch::factory()->create([
        'merchant_id' => $this->merchant->id,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_own_branch_1',
        'branch_id'         => $ownBranch->id,
        'use_bonus'         => false,
    ]);

    $response->assertOk();
    expect(Transaction::where('receipt_no', 'r_own_branch_1')->value('branch_id'))
        ->toBe($ownBranch->id);
});

it('rejects customer_id whose role is not Customer (e.g. another cashier id)', function () {
    $otherCashier = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $this->merchant->id,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $otherCashier->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_role_guard_1',
        'use_bonus'         => false,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    expect(Transaction::where('receipt_no', 'r_role_guard_1')->count())->toBe(0);
});

it('preview rejects customer_id whose role is not Customer', function () {
    $admin = User::factory()->create([
        'role'        => UserRole::Admin,
        'merchant_id' => null,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/preview', [
        'customer_id'       => $admin->id,
        'sale_amount_cents' => 5000,
        'use_bonus'         => false,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
});
