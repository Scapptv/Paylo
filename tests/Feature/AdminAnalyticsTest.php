<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4.1 — Admin Analytics controller (render + days filter + authz).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Cache::flush(); // analytics cache-i testlər arası sızmasın
    $this->admin    = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $customer       = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $merchant       = Merchant::factory()->create(['status' => 'active']);
    app(LedgerService::class)->earn(customer: $customer, merchant: $merchant, amount: BonusValue::fromAzn(12.00));
});

it('Phase 4.1: renders analytics with all chart datasets', function () {
    $this->actingAs($this->admin)->get('/admin/analytics')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Analytics')
            ->where('analytics.days', 30)
            ->where('filters.days', 30)
            ->has('analytics.kpis')
            ->has('analytics.dailyFlow', 30)
            ->has('analytics.liabilityTrend', 30)
            ->has('analytics.typeBreakdown', 7)
            ->has('analytics.topMerchants')
            ->where('analytics.kpis.earnedAll', 1200)
        );
});

it('Phase 4.1: respects the days window', function () {
    $this->actingAs($this->admin)->get('/admin/analytics?days=7')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('analytics.days', 7)
            ->where('filters.days', 7)
            ->has('analytics.dailyFlow', 7)
        );
});

it('Phase 4.1: falls back to 30 for a disallowed window', function () {
    $this->actingAs($this->admin)->get('/admin/analytics?days=999')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.days', 30));
});

it('Phase 4.1: blocks non-admin', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $owner = User::factory()->create(['role' => UserRole::MerchantOwner, 'merchant_id' => $merchant->id, 'is_active' => true]);

    $this->actingAs($owner)->get('/admin/analytics')->assertStatus(403);
});
