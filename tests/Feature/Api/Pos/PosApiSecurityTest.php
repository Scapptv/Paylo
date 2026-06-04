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
});

/*
|--------------------------------------------------------------------------
| Audit fix #1 — Reverse ability segregation
|--------------------------------------------------------------------------
| Web POS-da reverse yalnız merchant_owner/staff/admin üçündür (audit P-4).
| API-də ayrı `pos:reverse` ability tələb olunur — sızdırılmış sale-token-i
| (pos:write only) batch reverse ilə müştəri bonus drenajı edə bilməz.
*/

it('blocks pos:write-only token from reversing a sale (P-4 ability segregation)', function () {
    // Create a real completed tx to attempt reverse against
    Transaction::create([
        'receipt_no'      => 'r-target',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->posUser->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => TransactionStatus::Completed,
        'occurred_at'     => now(),
    ]);

    Sanctum::actingAs($this->posUser, abilities: ['pos:write']);

    $this->postJson('/api/v1/pos/sale/r-target/reverse', [
        'return_receipt_no' => 'RET-001',
    ])->assertStatus(403);

    // Tx must remain Completed — no reverse side effect.
    expect(Transaction::where('receipt_no', 'r-target')->first()->status)
        ->toBe(TransactionStatus::Completed);
});

it('allows pos:reverse token to reverse a sale', function () {
    Transaction::create([
        'receipt_no'      => 'r-target-2',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->posUser->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => TransactionStatus::Completed,
        'occurred_at'     => now(),
    ]);

    Sanctum::actingAs($this->posUser, abilities: ['pos:write', 'pos:reverse']);

    $this->postJson('/api/v1/pos/sale/r-target-2/reverse', [
        'return_receipt_no' => 'RET-002',
    ])->assertOk()->assertJsonFragment(['status' => 'reversed']);
});

it('blocks pos:write-only token from sale completion endpoint? NO — write is sufficient', function () {
    // Sanity: pos:reverse should NOT be required for normal sales. pos:write alone OK.
    Sanctum::actingAs($this->posUser, abilities: ['pos:write']);

    $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r-normal-sale',
        'use_bonus'         => false,
    ])->assertOk();
});

it('blocks token with only pos:reverse (no pos:write) from completing a sale', function () {
    // Defense-in-depth — a reverse-only token must NOT be a free pass to complete sales.
    Sanctum::actingAs($this->posUser, abilities: ['pos:reverse']);

    $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r-revonly',
        'use_bonus'         => false,
    ])->assertStatus(403);
});

/*
|--------------------------------------------------------------------------
| Audit fix #2 — Idempotency cache keyed by token_id, not user_id
|--------------------------------------------------------------------------
| Bir merchant = bir POS user = bir çox token. Token A və B paylaşdığı
| user-id-li cache namespace-i Idempotency-Key cross-talk yarada bilərdi.
*/

it('isolates Idempotency-Key namespace by token (two tokens of the SAME merchant do not interfere)', function () {
    // Real Sanctum tokens — Sanctum::actingAs gives a TransientToken without id,
    // which can't exercise token-level namespacing. Production traffic uses real
    // PersonalAccessToken-s with distinct DB ids; we reproduce that path here.
    $newTokenA = $this->posUser->createToken('terminal-A', ['pos:write']);
    $newTokenB = $this->posUser->createToken('terminal-B', ['pos:write']);
    $tokenA = $newTokenA->plainTextToken;
    $tokenB = $newTokenB->plainTextToken;

    // Sanity: distinct PersonalAccessToken rows. If these are equal, Sanctum
    // is reusing the same model and the test below cannot meaningfully assert
    // token-level namespacing.
    expect($newTokenA->accessToken->id)->not->toBe($newTokenB->accessToken->id);

    $key = 'shared-key-' . str_repeat('a', 16);

    $first = $this->withHeaders([
        'Authorization'   => 'Bearer ' . $tokenA,
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'tokA-r-1',
        'use_bonus'         => false,
    ]);
    $first->assertOk()->assertJson(['idempotent' => false]);

    // Test framework artifact: Sanctum guard caches the resolved user within a
    // single test process. Real HTTP requests each get a fresh container; we
    // simulate that by clearing the guard before the second bearer is sent.
    auth()->forgetGuards();

    // Eyni key, fərqli body, AYRI token. Köhnə implementation 422 verirdi
    // (user_id namespace = shared); yeni token_id namespace ilə BU YENİ İŞDİR.
    $second = $this->withHeaders([
        'Authorization'   => 'Bearer ' . $tokenB,
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 9999,
        'receipt_no'        => 'tokB-r-1',
        'use_bonus'         => false,
    ]);

    $second->assertOk()->assertJson(['idempotent' => false]);
    expect(Transaction::count())->toBe(2);
});

it('still detects body conflict within the SAME token namespace', function () {
    // Defense-in-depth — token namespacing must not weaken intra-token conflict
    // detection. Same token + same key + diff body = 422 (correct client error).
    $token = $this->posUser->createToken('terminal-A', ['pos:write'])->plainTextToken;
    $key   = 'fixed-key-' . str_repeat('b', 16);

    $this->withHeaders([
        'Authorization'   => 'Bearer ' . $token,
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r-1',
        'use_bonus'         => false,
    ])->assertOk();

    $conflict = $this->withHeaders([
        'Authorization'   => 'Bearer ' . $token,
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 9999,  // different body
        'receipt_no'        => 'r-1',
        'use_bonus'         => false,
    ]);

    $conflict->assertStatus(422)->assertJsonValidationErrors(['Idempotency-Key']);
});
