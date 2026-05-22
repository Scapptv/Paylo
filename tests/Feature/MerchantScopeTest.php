<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| EnsureMerchantScope middleware — fail-fast davranışı.
| merchant_id request attribute yoxdursa səssiz 0 default ilə davam etməməlidir.
|--------------------------------------------------------------------------
*/

it('aborts with 403 when an authorized role has no merchant_id', function () {
    $cashier = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => null,
        'is_active'   => true,
    ]);

    $response = $this->actingAs($cashier)->get('/pos/sale');

    $response->assertStatus(403);
});

it('allows POS access when merchant_id is properly bound', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $customer = User::factory()->create(['role' => UserRole::Customer]);

    // JSON endpoint istifadə edirik — Vite manifest tələbini yan keçir, lakin sübut edir ki
    // merchant.scope middleware request-i bloklamır və controller normal işləyir.
    $response = $this->actingAs($cashier)->postJson('/pos/preview', [
        'customer_id'       => $customer->id,
        'sale_amount_cents' => 1000,
        'use_bonus'         => false,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['sale_amount', 'earn_amount', 'redeem_amount']);
});

it('fails the POS sale endpoint with 422 when scope is OK but payload invalid (proves middleware passed without silent 0)', function () {
    // Bu test əmin edir ki, middleware uğurla scope set edib və request validation-a çatıb,
    // controller-də `Merchant::findOrFail(0)` kimi maskalanmış 404 ssenarisi yoxdur.
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);

    $response = $this->actingAs($cashier)->postJson('/pos/sale', [
        // sale_amount_cents və receipt_no qəsdən YOXDUR
    ]);

    $response->assertStatus(422); // 500/404 deyil — middleware fail-fast deyil, validator failure-dur.
});
