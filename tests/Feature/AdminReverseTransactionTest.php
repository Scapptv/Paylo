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
| POST /admin/transactions/{transaction}/reverse — admin endpoint-i.
| Məcburi `reason` audit üçün; reason Adjustment meta-sında saxlanır.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);

    $this->merchant = Merchant::factory()->create([
        'status'   => 'active',
        'category' => 'grocery',
        'tier'     => 'standard',
    ]);
    $this->admin = User::factory()->create([
        'role'        => UserRole::Admin,
        'merchant_id' => null,
        'is_active'   => true,
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

    // Sale yarat ki, üzərində işləyək.
    $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_admin_rev_1',
        'use_bonus'         => false,
    ])->assertOk();

    $this->tx = Transaction::where('receipt_no', 'r_admin_rev_1')->firstOrFail();
});

it('admin can reverse a transaction with a mandatory reason; reason is persisted in adjustment meta when redeem exists', function () {
    // Redeem-i olan ayrı bir satış yaradaq ki, Adjustment meta-da reason-u görək.
    // Əvvəlcə bucket-ə balans qoyaq.
    Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['balance' => 800, 'earned_total' => 800]);

    $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 7000,
        'receipt_no'        => 'r_admin_rev_2',
        'use_bonus'         => true,
        'redeem_cents'      => 300,
    ])->assertOk();

    $tx2 = Transaction::where('receipt_no', 'r_admin_rev_2')->firstOrFail();

    $response = $this->actingAs($this->admin)
        ->postJson("/admin/transactions/{$tx2->id}/reverse", [
            'return_receipt_no' => 'rr_admin_2',
            'reason'            => 'Müştəri şikayət etdi: yanlış məbləğ daxil edilib.',
        ]);

    $response->assertOk()
        ->assertJson([
            'transaction_id'   => $tx2->id,
            'status'           => 'reversed',
            'already_reversed' => false,
        ]);

    // 2 entry — 1 Reversal (earn) + 1 Adjustment (redeem credit-back).
    expect($response->json('reverse_entries'))->toHaveCount(2);

    $types = collect($response->json('reverse_entries'))->pluck('type')->all();
    expect($types)->toContain('reversal')->toContain('adjustment');

    $adjustment = LedgerEntry::where('merchant_id', $this->merchant->id)
        ->where('type', LedgerEntryType::Adjustment)
        ->latest('id')
        ->firstOrFail();

    expect($adjustment->meta['reason'] ?? '')
        ->toContain('return_reversal')
        ->toContain('return_rr_admin_2')
        ->toContain('yanlış məbləğ');
});

it('requires a reason (at least 3 chars)', function () {
    $this->actingAs($this->admin)
        ->postJson("/admin/transactions/{$this->tx->id}/reverse", ['return_receipt_no' => 'rr_x'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($this->admin)
        ->postJson("/admin/transactions/{$this->tx->id}/reverse", [
            'return_receipt_no' => 'rr_x',
            'reason'            => 'ab',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    expect($this->tx->fresh()->status)->toBe(TransactionStatus::Completed);
});

it('requires return_receipt_no', function () {
    $this->actingAs($this->admin)
        ->postJson("/admin/transactions/{$this->tx->id}/reverse", ['reason' => 'audit'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['return_receipt_no']);

    expect($this->tx->fresh()->status)->toBe(TransactionStatus::Completed);
});

it('is idempotent — second reverse returns already_reversed', function () {
    $payload = ['return_receipt_no' => 'rr_idem', 'reason' => 'audit'];

    $first = $this->actingAs($this->admin)
        ->postJson("/admin/transactions/{$this->tx->id}/reverse", $payload)
        ->assertOk();
    expect($first->json('already_reversed'))->toBeFalse();

    $entriesAfter = LedgerEntry::count();

    $second = $this->actingAs($this->admin)
        ->postJson("/admin/transactions/{$this->tx->id}/reverse", $payload)
        ->assertOk();
    expect($second->json('already_reversed'))->toBeTrue();

    expect(LedgerEntry::count())->toBe($entriesAfter);
});

it('returns 404 for unknown transaction id', function () {
    $this->actingAs($this->admin)
        ->postJson('/admin/transactions/999999/reverse', [
            'return_receipt_no' => 'rr_unknown',
            'reason'            => 'audit',
        ])
        ->assertNotFound();
});

it('blocks non-admin roles', function () {
    $this->actingAs($this->cashier)
        ->postJson("/admin/transactions/{$this->tx->id}/reverse", [
            'return_receipt_no' => 'rr_block',
            'reason'            => 'audit',
        ])
        ->assertStatus(403);

    expect($this->tx->fresh()->status)->toBe(TransactionStatus::Completed);
});

it('blocks unauthenticated requests', function () {
    // Admin route-da `role:admin` middleware role yoxlaması apararkən
    // unauthenticated user üçün 403 qaytarır (rolu yoxdur). 401 yox.
    $this->postJson("/admin/transactions/{$this->tx->id}/reverse", [
        'return_receipt_no' => 'rr_anon',
        'reason'            => 'audit',
    ])
        ->assertForbidden();
});
