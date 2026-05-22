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
    $this->user = User::factory()->create([
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/wallet
|--------------------------------------------------------------------------
*/

it('returns wallet summary with buckets and aggregates', function () {
    $m1 = Merchant::factory()->create(['status' => 'active']);
    $m2 = Merchant::factory()->create(['status' => 'active']);

    Bucket::create([
        'user_id' => $this->user->id, 'merchant_id' => $m1->id,
        'balance' => 1500, 'earned_total' => 2000, 'redeemed_total' => 500,
    ]);
    Bucket::create([
        'user_id' => $this->user->id, 'merchant_id' => $m2->id,
        'balance' => 300, 'earned_total' => 300, 'redeemed_total' => 0,
    ]);

    Sanctum::actingAs($this->user, ['customer']);

    $response = $this->getJson('/api/v1/wallet');

    $response->assertOk()
        ->assertJsonStructure([
            'total_balance', 'total_earned_all_time', 'total_redeemed_all_time',
            'expiring_soon', 'buckets_count', 'buckets', 'recent_entries', 'currency',
        ])
        ->assertJson([
            'total_balance'           => 1800,
            'total_earned_all_time'   => 2300,
            'total_redeemed_all_time' => 500,
            'buckets_count'           => 2,
            'currency'                => 'AZN',
        ]);
});

it('returns an empty wallet with zeros for a brand-new customer', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->getJson('/api/v1/wallet')
        ->assertOk()
        ->assertJson([
            'total_balance'   => 0,
            'buckets_count'   => 0,
            'buckets'         => [],
            'recent_entries'  => [],
            'currency'        => 'AZN',
        ]);
});

it('includes recent ledger entries (latest 10) in wallet payload', function () {
    $m      = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery']);
    $ledger = app(LedgerService::class);

    foreach (range(1, 12) as $i) {
        $ledger->earn(
            customer:  $this->user,
            merchant:  $m,
            amount:    BonusValue::fromAzn(1.00),
            receiptNo: "r_wallet_recent_$i",
        );
    }

    Sanctum::actingAs($this->user, ['customer']);

    $response = $this->getJson('/api/v1/wallet');
    $response->assertOk();
    expect($response->json('recent_entries'))->toHaveCount(10);
});

it('requires authentication on /wallet', function () {
    $this->getJson('/api/v1/wallet')->assertStatus(401);
});
