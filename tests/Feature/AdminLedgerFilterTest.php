<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Admin roadmap Phase 2.3 — Redemptions/Refunds = Ledger-in `?type=` preset-ləri.
| Yeni səhifə yox: nav linkləri mövcud `LedgerController` type-filtrinə güvənir.
| Bu testlər həmin filtri kilidləyir (preset linklərin etibarlılıq zəmanəti).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->admin    = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $this->customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);

    // Qarışıq tiplər: earn ×2, redeem ×1, refund ×1 (overdraft olmadan).
    $ledger = app(LedgerService::class);
    $ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(10.00), receiptNo: 'r1');
    $earn2 = $ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(5.00), receiptNo: 'r2');
    $ledger->redeem(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(3.00));
    $ledger->refund($earn2);
});

it('Phase 2.3: ledger preset filters to redeem rows only', function () {
    $this->actingAs($this->admin)->get('/admin/ledger?type=redeem')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Ledger')
            ->where('filters.type', 'redeem')
            ->has('entries.data', 1)
            ->where('entries.data.0.type', 'redeem')
        );
});

it('Phase 2.3: ledger preset filters to refund rows only', function () {
    $this->actingAs($this->admin)->get('/admin/ledger?type=refund')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Ledger')
            ->where('filters.type', 'refund')
            ->has('entries.data', 1)
            ->where('entries.data.0.type', 'refund')
        );
});
