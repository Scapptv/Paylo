<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Admin roadmap Phase 2.4 — Settlement reconciliation HTTP wrapper.
| Hesablama `SettlementReconciler` servisindədir (CLI ilə paylaşılır); burada
| HTTP təbəqəsini yoxlayırıq: render, tampered-detection, filter, run, authz.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->admin    = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $this->customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);

    $ledger = app(LedgerService::class);
    $ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(10.00));
    $ledger->redeem(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(3.00));
});

it('Phase 2.4: renders reconciliation report with healthy data', function () {
    $this->actingAs($this->admin)->get('/admin/settlements?for=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settlements')
            ->where('report.checked', 1)
            ->where('report.mismatches', [])
            ->has('scopes')
            ->has('merchants')
        );
});

it('Phase 2.4: detects a tampered bucket balance', function () {
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['balance' => 999999]);

    $this->actingAs($this->admin)->get('/admin/settlements?for=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.mismatches', 1)
            ->where('report.mismatches.0.diffs.balance.actual', 999999)
        );
});

it('Phase 2.4: scopes report to a single merchant', function () {
    $merchantB = Merchant::factory()->create(['status' => 'active']);
    app(LedgerService::class)->earn(customer: $this->customer, merchant: $merchantB, amount: BonusValue::fromAzn(5.00));

    // merchant A filtri → yalnız 1 bucket yoxlanır (B daxil deyil).
    $this->actingAs($this->admin)->get('/admin/settlements?for=all&merchant_id=' . $this->merchant->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('report.checked', 1));
});

it('Phase 2.4: run records reconcile and flashes success when healthy', function () {
    $this->from('/admin/settlements')
        ->actingAs($this->admin)
        ->post('/admin/settlements/run', ['for' => 'all'])
        ->assertRedirect()
        ->assertSessionHas('success');
});

it('Phase 2.4: run flashes error when a mismatch is found', function () {
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['balance' => 999999]);

    $this->from('/admin/settlements')
        ->actingAs($this->admin)
        ->post('/admin/settlements/run', ['for' => 'all'])
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('Phase 2.4: blocks non-admin from the report', function () {
    $owner = User::factory()->create(['role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true]);
    $this->actingAs($owner)->get('/admin/settlements')->assertStatus(403);
});

it('Phase 2.4: blocks non-admin from run', function () {
    $owner = User::factory()->create(['role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true]);
    $this->actingAs($owner)->post('/admin/settlements/run', ['for' => 'all'])->assertStatus(403);
});
