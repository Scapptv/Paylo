<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| 2026-06-04 dərin audit — launch-blocker fix-lərinin regression testləri.
|
|  WEB-1: Merchant dashboard cache `merchant_staff`-a maskasız telefon
|         sızdırırdı (cache açarı rola görə ayrılmırdı, maska warm-time-da
|         baked olunurdu). Fix: maska per-request tətbiq olunur.
|  WEB-2: POS preview/complete deaktiv (is_active=false) müştəri qəbul edirdi.
|         Fix: customer_id qaydasına `is_active=true` əlavə edildi (web + API).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);

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
});

// ---------------------------------------------------------------------------
// WEB-1 — PII cache leak (maskalama cache-dən kənarda, per-request)
// ---------------------------------------------------------------------------

it('WEB-1: merchant_staff sees masked phone even when owner warmed the cache first', function () {
    $owner = User::factory()->create([
        'role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    $staff = User::factory()->create([
        'role' => UserRole::MerchantStaff, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    $customer = User::factory()->create([
        'role' => UserRole::Customer, 'phone' => '+994501234567', 'is_active' => true,
    ]);
    Bucket::create([
        'user_id' => $customer->id, 'merchant_id' => $this->merchant->id,
        'balance' => 500, 'earned_total' => 500, 'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    // 1) Owner əvvəlcə yükləyir → cache "isti" olur (raw, maskasız data).
    $this->actingAs($owner)->get('/merchant/dashboard')->assertOk();

    // 2) Staff sonra yükləyir (eyni cache TTL içində). Köhnə kodda staff
    //    owner-in cache-lədiyi MASKASIZ nömrəni görürdü. Fix: maska per-request.
    $response = $this->actingAs($staff)->get('/merchant/dashboard')->assertOk();
    $top      = $response->viewData('page')['props']['topCustomers'];

    expect($top)->toHaveCount(1);
    expect($top[0]['user']['phone'])->toBe('+994******567'); // maskalı qalmalıdır
});

it('WEB-1: merchant_owner sees full phone even when staff warmed the cache first', function () {
    $owner = User::factory()->create([
        'role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    $staff = User::factory()->create([
        'role' => UserRole::MerchantStaff, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    $customer = User::factory()->create([
        'role' => UserRole::Customer, 'phone' => '+994501234567', 'is_active' => true,
    ]);
    Bucket::create([
        'user_id' => $customer->id, 'merchant_id' => $this->merchant->id,
        'balance' => 500, 'earned_total' => 500, 'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    // Staff əvvəlcə cache-i isti edir, owner sonra tam nömrəni görməlidir.
    $this->actingAs($staff)->get('/merchant/dashboard')->assertOk();

    $response = $this->actingAs($owner)->get('/merchant/dashboard')->assertOk();
    $top      = $response->viewData('page')['props']['topCustomers'];

    expect($top[0]['user']['phone'])->toBe('+994501234567'); // owner üçün tam
});

// ---------------------------------------------------------------------------
// WEB-2 — POS sale deaktiv müştəri qəbul etməməlidir (web Inertia)
// ---------------------------------------------------------------------------

it('WEB-2: web POS complete rejects a deactivated customer', function () {
    $inactive = User::factory()->create([
        'role' => UserRole::Customer, 'is_active' => false,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $inactive->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_web2_inactive_1',
        'use_bonus'         => false,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    expect(Transaction::where('receipt_no', 'r_web2_inactive_1')->count())->toBe(0);
});

it('WEB-2: web POS preview rejects a deactivated customer', function () {
    $inactive = User::factory()->create([
        'role' => UserRole::Customer, 'is_active' => false,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/preview', [
        'customer_id'       => $inactive->id,
        'sale_amount_cents' => 5000,
        'use_bonus'         => false,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
});

it('WEB-2: web POS complete still accepts an active customer (regression)', function () {
    $active = User::factory()->create([
        'role' => UserRole::Customer, 'is_active' => true,
    ]);

    $response = $this->actingAs($this->cashier)->postJson('/pos/sale', [
        'customer_id'       => $active->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_web2_active_1',
        'use_bonus'         => false,
    ]);

    $response->assertOk()->assertJson(['status' => 'completed']);
    expect(Transaction::where('receipt_no', 'r_web2_active_1')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// WEB-2 — eyni invariant API POS (M2M) axınında da (kanonik tutarlılıq)
// ---------------------------------------------------------------------------

it('WEB-2: API POS complete rejects a deactivated customer', function () {
    $posUser = User::factory()->create([
        'role' => UserRole::PosTerminal, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    $inactive = User::factory()->create([
        'role' => UserRole::Customer, 'is_active' => false,
    ]);

    Sanctum::actingAs($posUser, abilities: ['pos:write']);

    $response = $this->postJson('/api/v1/pos/sale', [
        'customer_id'       => $inactive->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'r_api_web2_inactive_1',
        'use_bonus'         => false,
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    expect(Transaction::where('receipt_no', 'r_api_web2_inactive_1')->count())->toBe(0);
});
