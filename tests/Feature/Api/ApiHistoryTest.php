<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user     = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $this->merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery']);
    $this->ledger   = app(LedgerService::class);
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/history
|--------------------------------------------------------------------------
*/

it('returns paginated ledger history for the authenticated user only', function () {
    $other = User::factory()->create(['role' => UserRole::Customer]);

    foreach (range(1, 3) as $i) {
        $this->ledger->earn(
            customer: $this->user, merchant: $this->merchant,
            amount:   BonusValue::fromAzn(1.00), receiptNo: "r_h_$i",
        );
    }
    foreach (range(1, 2) as $i) {
        $this->ledger->earn(
            customer: $other, merchant: $this->merchant,
            amount:   BonusValue::fromAzn(1.00), receiptNo: "r_o_$i",
        );
    }

    Sanctum::actingAs($this->user, ['customer']);

    $response = $this->getJson('/api/v1/history?limit=20');

    $response->assertOk()->assertJsonStructure(['data', 'next_cursor', 'prev_cursor', 'has_more']);
    expect($response->json('data'))->toHaveCount(3);
});

it('filters history by type (earn only)', function () {
    // earn-only flow yaradır ki, hash chain və bucket düzgün dolsun.
    $this->ledger->earn(
        customer: $this->user, merchant: $this->merchant,
        amount:   BonusValue::fromAzn(5.00), receiptNo: 'r_e_1',
    );
    $this->ledger->redeem(
        customer: $this->user, merchant: $this->merchant,
        amount:   BonusValue::fromAzn(1.00), receiptNo: 'r_r_1',
    );

    Sanctum::actingAs($this->user, ['customer']);

    $response = $this->getJson('/api/v1/history?type=earn');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('filters history by merchant_id', function () {
    $other = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery']);

    foreach (range(1, 2) as $i) {
        $this->ledger->earn(
            customer: $this->user, merchant: $this->merchant,
            amount:   BonusValue::fromAzn(1.00), receiptNo: "r_m1_$i",
        );
    }
    foreach (range(1, 3) as $i) {
        $this->ledger->earn(
            customer: $this->user, merchant: $other,
            amount:   BonusValue::fromAzn(1.00), receiptNo: "r_m2_$i",
        );
    }

    Sanctum::actingAs($this->user, ['customer']);

    $response = $this->getJson('/api/v1/history?merchant_id=' . $other->id);
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('rejects invalid date range (to before from)', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->getJson('/api/v1/history?from=2026-05-10&to=2026-05-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['to']);
});

it('rejects limit greater than 50', function () {
    Sanctum::actingAs($this->user, ['customer']);
    $this->getJson('/api/v1/history?limit=51')->assertStatus(422)->assertJsonValidationErrors(['limit']);
});

it('requires authentication on /history', function () {
    $this->getJson('/api/v1/history')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/buckets/{bucket}/history
|--------------------------------------------------------------------------
*/

it('returns history for an owned bucket', function () {
    foreach (range(1, 2) as $i) {
        $this->ledger->earn(
            customer: $this->user, merchant: $this->merchant,
            amount:   BonusValue::fromAzn(1.00), receiptNo: "r_b_$i",
        );
    }
    $bucket = Bucket::where('user_id', $this->user->id)
        ->where('merchant_id', $this->merchant->id)->firstOrFail();

    Sanctum::actingAs($this->user, ['customer']);

    $response = $this->getJson("/api/v1/buckets/{$bucket->id}/history");
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
});

it('returns 403 when accessing another user\'s bucket history', function () {
    $other       = User::factory()->create(['role' => UserRole::Customer]);
    $otherBucket = Bucket::create([
        'user_id' => $other->id, 'merchant_id' => $this->merchant->id, 'balance' => 0,
    ]);

    Sanctum::actingAs($this->user, ['customer']);

    $this->getJson("/api/v1/buckets/{$otherBucket->id}/history")->assertStatus(403);
});
