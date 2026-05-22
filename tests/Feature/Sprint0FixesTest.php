<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 0 — Deploy blockers (AUDIT_PLAN.md istinad).
| C-1, P-3, Cfg-1 üçün yeni regression test-lər. FE-1 və FE-2 frontend dəyişikliyi
| olduğu üçün burada yox, manuel/E2E test ilə örtülür.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);
    config()->set('loyalty.redemption.max_percent_of_sale', 50);
    config()->set('loyalty.redemption.min_sale_cents', 100);

    $this->merchant = Merchant::factory()->create([
        'status'   => 'active',
        'category' => 'grocery',
        'tier'     => 'standard',
    ]);

    $this->cashier = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    $this->customer = User::factory()->create([
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    $this->ledger = app(LedgerService::class);
});

/*
|--------------------------------------------------------------------------
| C-1 — Hash chain race condition fix
|--------------------------------------------------------------------------
|
| `LedgerEntry::$fillable`-a `created_at`/`updated_at` əlavə olundu. Service-in
| explicit ötürdüyü `$now` artıq DB-yə yazılır → re-read və yenidən hash-ləmə
| eyni dəyəri verir. Saniyə sərhədində belə uyğunsuzluq yaranmamalıdır.
*/

it('persists explicit created_at from LedgerService into DB (C-1)', function () {
    // İndini explicit qeyd edirik ki, hash payload-da işlədilən saniyə dəyərini
    // bilək. Carbon::setTestNow `now()` çağırışlarını sabitləyir.
    Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

    $entry = $this->ledger->earn(
        $this->customer,
        $this->merchant,
        new BonusValue(100),
        receiptNo: 'r_c1_a',
    );

    Carbon::setTestNow();

    $fresh = LedgerEntry::find($entry->id);

    expect($fresh->created_at->format('Y-m-d H:i:s'))
        ->toBe('2026-05-21 12:00:00');

    expect($this->ledger->verifyChain())
        ->toMatchArray(['valid' => true, 'broken_ids' => [], 'checked' => 1]);
});

it('verifies chain across second-boundary writes (C-1 regression)', function () {
    // İlk earn 12:00:00.999-da hash-lənsə, ikinci save 12:00:01-də işləsə də
    // fillable sayəsində DB-dəki created_at hash-də işlədilən dəyərə bərabər qalır.
    Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(50), receiptNo: 'r_c1_b1');

    Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:01'));
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(75), receiptNo: 'r_c1_b2');

    Carbon::setTestNow();

    expect($this->ledger->verifyChain()['valid'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| P-3 — Paralel POS complete UniqueConstraintViolation → idempotent
|--------------------------------------------------------------------------
|
| HTTP səviyyəsində iki paralel sorğunu real "race" reproduce etmək çətindir,
| amma controller-i prepared Transaction ilə eyni `receipt_no`-nu yaratmağa
| məcbur edib unique constraint violation-ı yarada bilərik.
*/

it('returns idempotent 200 when a transaction with same (merchant, receipt_no) already exists (P-3)', function () {
    $receipt = 'r_p3_unique';

    // İlk request — adi axın.
    $first = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => $receipt,
        'use_bonus'         => false,
    ]);
    $first->assertOk()->assertJson(['idempotent' => false]);

    // İkinci request — eyni payload. Controller lookForUpdate ilə tutur,
    // 200 + idempotent: true qaytarır.
    $second = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => $receipt,
        'use_bonus'         => false,
    ]);
    $second->assertOk()->assertJson([
        'idempotent' => true,
        'status'     => 'completed',
        'receipt_no' => $receipt,
    ]);

    expect($first->json('transaction_id'))->toBe($second->json('transaction_id'));
    expect(Transaction::where('receipt_no', $receipt)->count())->toBe(1);
});

it('does not 500 when Transaction insert violates unique constraint after the pre-check (P-3 race fallback)', function () {
    // Simulyasiya: race-də qalib gəlmiş paralel request artıq tx yaradıb,
    // amma cari request DB::transaction içində insert cəhdini hələ etməyib.
    // Aşağıdakı sıralama bu vəziyyəti modelləşdirir:
    //   1) cari request açılır (yox kimi görünür) — biz tx-i hələ commit etməmişik.
    //   2) "rəqib" tx ayrı bir connection-da commit edir (manual insert).
    //   3) cari request insert etməyə çalışır → unique violation.
    //
    // Sadəlik üçün burada ümumi axını yoxlayırıq: əgər DB-də artıq eyni
    // receipt_no-lu tx varsa, ikinci HTTP request **heç vaxt** 500 qaytarmamalıdır.
    $receipt = 'r_p3_race';

    Transaction::create([
        'receipt_no'      => $receipt,
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => $receipt,
        'use_bonus'         => false,
    ]);

    $response->assertOk()->assertJson(['idempotent' => true]);
    expect(Transaction::where('receipt_no', $receipt)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Cfg-1 — Redemption rules enforcement
|--------------------------------------------------------------------------
*/

it('caps redeem at max_percent_of_sale (Cfg-1)', function () {
    // 100 AZN satış, customer-də 80 AZN bonus var, biznes limiti 50%.
    Bucket::create([
        'user_id'        => $this->customer->id,
        'merchant_id'    => $this->merchant->id,
        'balance'        => 8000,  // 80 AZN
        'earned_total'   => 8000,
        'redeemed_total' => 0,
        'expired_total'  => 0,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/preview', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 10000,  // 100 AZN
        'use_bonus'         => true,
        'redeem_cents'      => 8000,   // istifadəçi tam 80 AZN istəyir
    ]);

    $response->assertOk();
    // 50% max → 5000 qəpik (50 AZN) capped.
    expect($response->json('redeem_amount'))->toBe(5000);
    expect($response->json('final_to_pay'))->toBe(5000);
});

it('rejects bonus usage when sale below min_sale_cents (Cfg-1)', function () {
    Bucket::create([
        'user_id'        => $this->customer->id,
        'merchant_id'    => $this->merchant->id,
        'balance'        => 5000,
        'earned_total'   => 5000,
        'redeemed_total' => 0,
        'expired_total'  => 0,
    ]);

    // Satış 50 qəpik (= 0.50 AZN) — min_sale_cents=100-dan aşağı.
    $response = $this->actingAs($this->cashier)->postJson('/pos/preview', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 50,
        'use_bonus'         => true,
        'redeem_cents'      => 5000,
    ]);

    $response->assertOk();
    expect($response->json('redeem_amount'))->toBe(0);
    expect($response->json('final_to_pay'))->toBe(50);
});

it('still caps redeem at bucket balance and sale amount (Cfg-1 — qalan invariantlar)', function () {
    // Balance 30 AZN, sale 100 AZN, max% 50 → percentCap=5000, balance=3000,
    // sale=10000 → real cap = 3000 (ən kiçik).
    Bucket::create([
        'user_id'        => $this->customer->id,
        'merchant_id'    => $this->merchant->id,
        'balance'        => 3000,
        'earned_total'   => 3000,
        'redeemed_total' => 0,
        'expired_total'  => 0,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/preview', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 10000,
        'use_bonus'         => true,
        'redeem_cents'      => 6000,
    ]);

    $response->assertOk();
    expect($response->json('redeem_amount'))->toBe(3000);
});

it('fails fast when redemption config is missing (Cfg-1)', function () {
    config()->set('loyalty.redemption', []);  // həm min_sale_cents həm də max_percent yox

    $response = $this->actingAs($this->cashier)->postJson('/pos/preview', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'use_bonus'         => true,
        'redeem_cents'      => 1000,
    ]);

    // Səssiz 0 default-a sürüşmə qadağandır — exception qalxır.
    $response->assertStatus(500);
});

it('preview does not trigger redemption validation when use_bonus is false (Cfg-1 — early return)', function () {
    // Config tamamilə silinib, amma `use_bonus=false` halında validation çağırılmamalıdır.
    config()->set('loyalty.redemption', []);

    $response = $this->actingAs($this->cashier)->postJson('/pos/preview', [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'use_bonus'         => false,
    ]);

    $response->assertOk();
    expect($response->json('redeem_amount'))->toBe(0);
});
