<?php

declare(strict_types=1);

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\UserRole;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 1 — Security hardening (AUDIT_PLAN.md istinad).
|
| Bu fayl bütün Sprint 1 tapıntıları üçün regression testlərini toplayır.
| Hər tapıntı öz `describe()` bloku altındadır — gələcəkdə tək-tək istinad asanlaşsın.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200, 'restaurant' => 500]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);
    config()->set('loyalty.redemption.max_percent_of_sale', 50);
    config()->set('loyalty.redemption.min_sale_cents', 100);
});

/*
|--------------------------------------------------------------------------
| C-2 — Cross-merchant ledger entry leak (Transaction relation scope)
|--------------------------------------------------------------------------
|
| `receipt_no` global unique deyil — yalnız (merchant_id, receipt_no) cütü.
| İki fərqli merchant eyni qəbz nömrəsini işlədə bilər. `Transaction::ledgerEntries`
| relation `merchant_id` ilə də scope-lanmalıdır ki, cross-merchant leak olmasın.
*/

it('Transaction::ledgerEntries scopes by merchant_id (C-2)', function () {
    $merchantA = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $merchantB = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $customer  = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $ledger = app(LedgerService::class);

    // Eyni `receipt_no = 'R-001'` iki fərqli merchant üçün.
    $entryA = $ledger->earn($customer, $merchantA, new BonusValue(100), receiptNo: 'R-001');
    $entryB = $ledger->earn($customer, $merchantB, new BonusValue(200), receiptNo: 'R-001');

    $txA = Transaction::create([
        'receipt_no'      => 'R-001',
        'merchant_id'     => $merchantA->id,
        'cashier_id'      => null,
        'user_id'         => $customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $txB = Transaction::create([
        'receipt_no'      => 'R-001',
        'merchant_id'     => $merchantB->id,
        'cashier_id'      => null,
        'user_id'         => $customer->id,
        'sale_amount'     => 10000,
        'earned_amount'   => 200,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $txAEntries = $txA->ledgerEntries()->get();
    $txBEntries = $txB->ledgerEntries()->get();

    // Transaction A yalnız öz merchant-ının entry-sini görsün.
    expect($txAEntries->pluck('id')->all())->toBe([$entryA->id]);
    // Transaction B yalnız öz merchant-ının entry-sini görsün.
    expect($txBEntries->pluck('id')->all())->toBe([$entryB->id]);
});

it('Transaction::ledgerEntries throws on eager load to prevent silent leak (C-2 — composite key limitation)', function () {
    // MƏHDUDIYYƏT: Laravel HasMany composite foreign key (merchant_id, receipt_no)
    // dəstəkləmir. Eager load relation-ı fresh instance üzərində çağırır →
    // `$this->merchant_id` null → scope səssizcə boş gəlir.
    // Yanlış data göstərməkdən qaçmaq üçün bu halda explicit LogicException atırıq.
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    Transaction::create([
        'receipt_no'      => 'R-x',
        'merchant_id'     => $merchant->id,
        'cashier_id'      => null,
        'user_id'         => $customer->id,
        'sale_amount'     => 1000,
        'earned_amount'   => 0,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    expect(fn () => Transaction::with('ledgerEntries')->get())
        ->toThrow(\LogicException::class, 'eager loading');
});
