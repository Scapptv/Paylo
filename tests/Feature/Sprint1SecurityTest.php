<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use App\Modules\Api\Services\RotatingQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

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

/*
|--------------------------------------------------------------------------
| P-1 — POS lookup `is_active` filter
|--------------------------------------------------------------------------
*/

it('POS lookup ignores inactive customer (P-1)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $deactivated = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => false,
        'customer_qr' => 'qr_deactivated_xyz',
    ]);

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/' . $deactivated->customer_qr);

    // Status uniformdur (enumeration qarşısı), lakin customer payload-da null
    // olur — POS yenidən sale ekranını açmır.
    $response->assertOk()
        ->assertJson(['status' => 'not_found', 'customer' => null, 'bucket' => null]);
});

it('POS lookup still finds active customer (P-1 — regression)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $active = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_active_xyz',
    ]);

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/' . $active->customer_qr);

    $response->assertOk()->assertJsonPath('status', 'ok')
        ->assertJsonPath('customer.id', $active->id);
});

/*
|--------------------------------------------------------------------------
| P-2 — Static `customer.qr` response-dan çıxarılıb
|--------------------------------------------------------------------------
*/

it('POS lookup response does not leak static customer_qr (P-2)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_static_secret',
    ]);

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/' . $customer->customer_qr);

    $response->assertOk()->assertJsonPath('customer.id', $customer->id);

    // Response-da `customer.qr` field-i mövcud olmamalıdır.
    $response->assertJsonMissingPath('customer.qr');

    // Plain text olaraq da static QR cavabda görünməsin (defense-in-depth).
    expect($response->getContent())->not->toContain('qr_static_secret');
});

/*
|--------------------------------------------------------------------------
| P-12 — `markUsed` failure non-fatal
|--------------------------------------------------------------------------
*/

it('POS lookup succeeds even when RotatingQrService::markUsed throws (P-12)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_p12_customer',
    ]);

    // RotatingQrService-i mock-la → `markUsed` istisna atır, `verify` valid token
    // qaytarır. Lookup hələ də 200 + ok cavab verməlidir, exception sızmamalıdır.
    $this->mock(RotatingQrService::class, function (MockInterface $mock) use ($customer) {
        $mock->shouldReceive('verify')->andReturn([
            'valid'   => true,
            'user_qr' => $customer->customer_qr,
            'reason'  => null,
            'hmac'    => 'abc1234567890def',
            'exp'     => time() + 60,
        ]);
        $mock->shouldReceive('markUsed')
            ->once()
            ->andThrow(new \RuntimeException('cache backend down'));
    });

    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')
        ->withArgs(fn ($message) => str_contains($message, 'mark_used_failed'))
        ->once();

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/qr1.qr_p12_customer.9999999999.deadbeefdeadbeef');

    $response->assertOk()->assertJsonPath('status', 'ok');
});
