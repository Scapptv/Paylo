<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 4 — quick wins: P-8, P-9, H-1, Csh-2, Csh-3, Csh-4 davranışı.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);

    $this->merchant = Merchant::factory()->create([
        'status' => 'active', 'category' => 'grocery', 'tier' => 'standard',
    ]);
    $this->cashier = User::factory()->create([
        'role' => UserRole::Cashier, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    $this->customer = User::factory()->create([
        'role' => UserRole::Customer, 'merchant_id' => null,
    ]);
});

// ---- P-8: redeem_cents prohibited unless use_bonus=true ----

it('P-8: rejects redeem_cents when use_bonus=false', function () {
    $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_p8_1',
        'use_bonus'         => false,
        'redeem_cents'      => 100,
    ])->assertStatus(422)->assertJsonValidationErrors(['redeem_cents']);
});

it('P-8: accepts redeem_cents when use_bonus=true', function () {
    // Bucket-də balans yarat — redeem oluna bilsin.
    Bucket::create([
        'user_id' => $this->customer->id, 'merchant_id' => $this->merchant->id,
        'balance' => 100, 'earned_total' => 100, 'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_p8_2',
        'use_bonus'         => true,
        'redeem_cents'      => 100,
    ])->assertOk();
});

it('P-8: accepts omitting redeem_cents when use_bonus=false', function () {
    $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_p8_3',
        'use_bonus'         => false,
    ])->assertOk();
});

// ---- P-9: receipt_no regex ----

it('P-9: rejects receipt_no with spaces or special chars', function () {
    foreach (['rcpt with space', 'r;42', 'r,42', 'r/42', 'r#42'] as $bad) {
        $this->actingAs($this->cashier)->postJson('/pos/sale', [
            'customer_id'       => $this->customer->id,
            'sale_amount_cents' => 5000,
            'receipt_no'        => $bad,
            'use_bonus'         => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['receipt_no']);
    }
});

it('P-9: accepts alphanumeric, dash and underscore', function () {
    foreach (['r_42', 'r-42', 'RCPT-2026-001', 'abc_123_XYZ'] as $i => $ok) {
        $this->actingAs($this->cashier)->postJson('/pos/sale', [
            'customer_id'       => $this->customer->id,
            'sale_amount_cents' => 1000 + $i,
            'receipt_no'        => $ok,
            'use_bonus'         => false,
        ])->assertOk();
    }
});

// ---- H-1: EnsureRole unknown role throws LogicException ----

it('H-1: EnsureRole middleware throws LogicException on unknown role', function () {
    Route::middleware(['web', 'role:non_existent_role'])
        ->get('/_test/h1', fn () => 'ok');

    // Auth keçməsi üçün acting-as.
    $this->actingAs($this->cashier);

    // LogicException dev-üçün 500 kimi çıxır; mesaj fail-fast yoxlanışıdır.
    expect(fn () => $this->withoutExceptionHandling()->get('/_test/h1'))
        ->toThrow(\LogicException::class, 'non_existent_role');
});

// ---- Csh-2, Csh-3, Csh-4: shift controller ----

it('Csh-3: shift recentTransactions limited to 20 via SQL not memory', function () {
    // 25 satış yarat → recentTransactions yalnız 20 qaytarmalıdır.
    for ($i = 0; $i < 25; $i++) {
        Transaction::create([
            'receipt_no'      => "r_shift_{$i}",
            'merchant_id'     => $this->merchant->id,
            'cashier_id'      => $this->cashier->id,
            'user_id'         => $this->customer->id,
            'sale_amount'     => 1000,
            'earned_amount'   => 20,
            'redeemed_amount' => 0,
            'status'          => 'completed',
            'occurred_at'     => now(),
        ]);
    }

    $response = $this->actingAs($this->cashier)->get('/cashier/shift');
    $response->assertOk();

    $page    = $response->viewData('page');
    $props   = $page['props'];
    $recent  = $props['recentTransactions'];

    expect($recent)->toHaveCount(20);
    expect($props['shiftStats']['transactions'])->toBe(25);
});

it('Csh-4: reversed/refunded counted separately; totalSales = completed only', function () {
    Transaction::create([
        'receipt_no'      => 'r_csh4_completed',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 1000, 'earned_amount' => 20, 'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);
    Transaction::create([
        'receipt_no'      => 'r_csh4_reversed',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 5000, 'earned_amount' => 100, 'redeemed_amount' => 0,
        'status'          => 'reversed',
        'occurred_at'     => now(),
    ]);

    $response = $this->actingAs($this->cashier)->get('/cashier/shift');
    $stats    = $response->viewData('page')['props']['shiftStats'];

    expect($stats['transactions'])->toBe(2);
    expect($stats['completedCount'])->toBe(1);
    expect($stats['reversedCount'])->toBe(1);
    expect($stats['refundedCount'])->toBe(0);
    expect($stats['totalSales'])->toBe(1000); // YALNIZ completed sayılır
    expect($stats['totalEarned'])->toBe(20);
});

it('Csh-2: shift ignores transactions from another merchant even if cashier_id matches', function () {
    $other = Merchant::factory()->create(['status' => 'active']);

    // Eyni cashier_id, BAŞQA merchant — bu sətir reportda görsənməməlidir.
    Transaction::create([
        'receipt_no'      => 'r_other_merchant',
        'merchant_id'     => $other->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 99999, 'earned_amount' => 0, 'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);
    // Düzgün merchant-da bir tx.
    Transaction::create([
        'receipt_no'      => 'r_my_merchant',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 500, 'earned_amount' => 10, 'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $response = $this->actingAs($this->cashier)->get('/cashier/shift');
    $stats    = $response->viewData('page')['props']['shiftStats'];

    expect($stats['transactions'])->toBe(1);
    expect($stats['totalSales'])->toBe(500);
});
