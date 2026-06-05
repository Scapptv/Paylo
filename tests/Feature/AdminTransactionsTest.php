<?php

declare(strict_types=1);

use App\Core\Enums\TransactionStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Admin roadmap Phase 1.2 — Transactions UI (Inertia): siyahı + web reverse.
| API/JSON reverse kontraktı AdminReverseTransactionTest ilə qorunur.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->admin    = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    // Reverse oluna bilən tamamlanmış satış (earn ledger entry + transaction).
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(100), receiptNo: 'r_ui_1');
    $this->tx = Transaction::create([
        'receipt_no' => 'r_ui_1', 'merchant_id' => $this->merchant->id,
        'cashier_id' => $this->admin->id, 'user_id' => $this->customer->id,
        'sale_amount' => 5000, 'earned_amount' => 100, 'redeemed_amount' => 0,
        'status' => 'completed', 'occurred_at' => now(),
    ]);
});

it('Phase 1.2: renders the transactions list to admin', function () {
    $this->actingAs($this->admin)->get('/admin/transactions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Transactions')
            ->has('transactions.data', 1)
            ->has('filters')
        );
});

it('Phase 1.2: filters transactions by status', function () {
    $this->actingAs($this->admin)->get('/admin/transactions?status=reversed')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('transactions.data', 0));
});

it('Phase 1.2: admin reverses via the web form — redirect + success flash + status change', function () {
    $response = $this->actingAs($this->admin)->post(route('admin.transactions.reverse', $this->tx), [
        'return_receipt_no' => 'RET-UI-1',
        'reason'            => 'müştəri qaytardı',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect(Transaction::find($this->tx->id)->status)->toBe(TransactionStatus::Reversed);
});

it('Phase 1.2: web reverse of already-spent bonus flashes an error, no status change', function () {
    // Müştəri earn-i xərclədi → balance 0; earn clawback mümkün deyil (redeem yox).
    $this->ledger->redeem($this->customer, $this->merchant, new BonusValue(100), receiptNo: 'r_spend_ui');

    $response = $this->actingAs($this->admin)->post(route('admin.transactions.reverse', $this->tx), [
        'return_receipt_no' => 'RET-UI-2',
        'reason'            => 'qaytarma cəhdi',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(Transaction::find($this->tx->id)->status)->toBe(TransactionStatus::Completed);
});

it('Phase 1.2: blocks non-admin from the transactions list', function () {
    $owner = User::factory()->create([
        'role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);

    $this->actingAs($owner)->get('/admin/transactions')->assertStatus(403);
});
