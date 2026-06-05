<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Admin roadmap Phase 2.1 — Per-merchant Buckets read-view.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $this->mA = Merchant::factory()->create(['status' => 'active', 'name' => 'Alpha Store']);
    $this->mB = Merchant::factory()->create(['status' => 'active', 'name' => 'Beta Shop']);

    $this->cust = User::factory()->create([
        'role' => UserRole::Customer, 'name' => 'Test Musteri', 'email' => 'findme@test.az', 'is_active' => true,
    ]);
    $other = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Other', 'is_active' => true]);

    Bucket::create(['user_id' => $this->cust->id, 'merchant_id' => $this->mA->id,
        'balance' => 500, 'earned_total' => 700, 'redeemed_total' => 200, 'expired_total' => 0]);
    Bucket::create(['user_id' => $other->id, 'merchant_id' => $this->mB->id,
        'balance' => 300, 'earned_total' => 300, 'redeemed_total' => 0, 'expired_total' => 0]);
});

it('Phase 2.1: renders the buckets list with total locked', function () {
    $this->actingAs($this->admin)->get('/admin/buckets')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Buckets')
            ->has('buckets.data', 2)
            ->where('totalLocked', 800) // 500 + 300
            ->has('merchants')
        );
});

it('Phase 2.1: filters by merchant (totalLocked scoped to filter)', function () {
    $this->actingAs($this->admin)->get('/admin/buckets?merchant_id=' . $this->mA->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('buckets.data', 1)
            ->where('totalLocked', 500)
        );
});

it('Phase 2.1: filters by customer name/email', function () {
    $this->actingAs($this->admin)->get('/admin/buckets?q=findme')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('buckets.data', 1));
});

it('Phase 2.1: blocks non-admin roles', function () {
    $owner = User::factory()->create([
        'role' => UserRole::MerchantOwner, 'merchant_id' => $this->mA->id, 'is_active' => true,
    ]);
    $this->actingAs($owner)->get('/admin/buckets')->assertStatus(403);
});
