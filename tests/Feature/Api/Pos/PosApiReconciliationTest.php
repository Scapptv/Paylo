<?php

declare(strict_types=1);

use App\Core\Enums\TransactionStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->posUser  = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);
    $this->customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'merchant_id' => null,
    ]);

    Sanctum::actingAs($this->posUser, abilities: ['pos:write']);
});

/**
 * `occurred_at` ilə fərqli vaxtlarda tx yaradır — feed-də sıralama / since filteri
 * üçün test data.
 */
function makeTx(Merchant $m, User $c, string $receipt, string $occurredAt, TransactionStatus $status = TransactionStatus::Completed): Transaction
{
    return Transaction::create([
        'receipt_no'      => $receipt,
        'merchant_id'     => $m->id,
        'cashier_id'      => $c->id,
        'user_id'         => $c->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => $status,
        'occurred_at'     => $occurredAt,
    ]);
}

it('returns the merchant\'s transactions ordered by occurred_at DESC', function () {
    makeTx($this->merchant, $this->customer, 'r-1', '2026-05-01 10:00:00');
    makeTx($this->merchant, $this->customer, 'r-2', '2026-05-02 10:00:00');
    makeTx($this->merchant, $this->customer, 'r-3', '2026-05-03 10:00:00');

    $response = $this->getJson('/api/v1/pos/transactions');

    $response->assertOk();
    $receipts = collect($response->json('data'))->pluck('receipt_no')->all();
    expect($receipts)->toBe(['r-3', 'r-2', 'r-1']);
});

it('isolates transactions by merchant scope — no cross-merchant leak', function () {
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);
    $otherPos      = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $otherMerchant->id,
    ]);

    makeTx($this->merchant, $this->customer, 'own-1', '2026-05-01 10:00:00');
    makeTx($otherMerchant,  $this->customer, 'foreign-1', '2026-05-01 10:00:00');

    $response = $this->getJson('/api/v1/pos/transactions');

    $receipts = collect($response->json('data'))->pluck('receipt_no')->all();
    expect($receipts)->toBe(['own-1']);
});

it('filters by since timestamp (only tx with occurred_at >= since)', function () {
    makeTx($this->merchant, $this->customer, 'old',   '2026-05-01 10:00:00');
    makeTx($this->merchant, $this->customer, 'new-1', '2026-05-05 10:00:00');
    makeTx($this->merchant, $this->customer, 'new-2', '2026-05-06 10:00:00');

    $response = $this->getJson('/api/v1/pos/transactions?since=2026-05-04T00:00:00%2B04:00');

    $receipts = collect($response->json('data'))->pluck('receipt_no')->all();
    expect($receipts)->toBe(['new-2', 'new-1']);
});

it('filters by until timestamp (only tx with occurred_at <= until)', function () {
    makeTx($this->merchant, $this->customer, 'before', '2026-05-01 10:00:00');
    makeTx($this->merchant, $this->customer, 'after',  '2026-05-10 10:00:00');

    $response = $this->getJson('/api/v1/pos/transactions?until=2026-05-05T00:00:00%2B04:00');

    $receipts = collect($response->json('data'))->pluck('receipt_no')->all();
    expect($receipts)->toBe(['before']);
});

it('filters by status enum value', function () {
    makeTx($this->merchant, $this->customer, 'done',     '2026-05-01 10:00:00', TransactionStatus::Completed);
    makeTx($this->merchant, $this->customer, 'reversed', '2026-05-02 10:00:00', TransactionStatus::Reversed);

    $response = $this->getJson('/api/v1/pos/transactions?status=reversed');

    $receipts = collect($response->json('data'))->pluck('receipt_no')->all();
    expect($receipts)->toBe(['reversed']);
});

it('paginates with cursor — page 2 picks up from where page 1 ended, no overlap', function () {
    for ($i = 1; $i <= 5; $i++) {
        makeTx($this->merchant, $this->customer, "p-{$i}", "2026-05-0{$i} 10:00:00");
    }

    $page1 = $this->getJson('/api/v1/pos/transactions?limit=2');
    $page1->assertOk()->assertJsonStructure(['data', 'next_cursor', 'has_more']);
    expect($page1->json('data'))->toHaveCount(2);
    expect($page1->json('has_more'))->toBeTrue();

    $cursor = urlencode($page1->json('next_cursor'));
    $page2 = $this->getJson("/api/v1/pos/transactions?limit=2&cursor={$cursor}");
    expect($page2->json('data'))->toHaveCount(2);

    $page1Receipts = collect($page1->json('data'))->pluck('receipt_no')->all();
    $page2Receipts = collect($page2->json('data'))->pluck('receipt_no')->all();
    expect(array_intersect($page1Receipts, $page2Receipts))->toBeEmpty();
});

it('does not leak PII — no email, phone, customer_qr fields', function () {
    makeTx($this->merchant, $this->customer, 'pii-check', '2026-05-01 10:00:00');

    $response = $this->getJson('/api/v1/pos/transactions');
    $tx = $response->json('data.0');

    expect($tx)->not->toHaveKey('email');
    expect($tx)->not->toHaveKey('phone');
    expect($tx)->not->toHaveKey('customer_qr');
    expect($tx)->not->toHaveKey('user');  // No expanded user object
});

it('validates limit max=200 (rejects 201)', function () {
    $this->getJson('/api/v1/pos/transactions?limit=201')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['limit']);
});

it('validates until cannot be before since', function () {
    $this->getJson('/api/v1/pos/transactions?since=2026-05-10&until=2026-05-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['until']);
});

it('validates status against the enum allowlist', function () {
    $this->getJson('/api/v1/pos/transactions?status=garbage')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('rejects unauthenticated requests with 401', function () {
    auth()->forgetGuards();
    $this->getJson('/api/v1/pos/transactions')->assertStatus(401);
});

it('rejects customer-ability tokens with 403', function () {
    Sanctum::actingAs($this->posUser, abilities: ['customer']);
    $this->getJson('/api/v1/pos/transactions')->assertStatus(403);
});
