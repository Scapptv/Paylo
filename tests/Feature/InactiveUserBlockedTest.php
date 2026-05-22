<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| EnsureUserIsActive — deaktiv user heç bir authenticated route-a çatmasın.
|--------------------------------------------------------------------------
*/

it('allows an active authenticated user to reach a protected route', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);

    $response = $this->actingAs($cashier)
        ->get('/pos/customer/UNKNOWN_QR_XYZ');

    // Middleware keçib endpoint işləyib (404 deyil — 200 uniform response).
    expect($response->status())->not->toBe(403);
    expect($response->status())->toBe(200);
});

it('blocks an inactive authenticated user with HTTP 403 on any protected route', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => false,
    ]);

    $this->actingAs($cashier)
        ->get('/pos/customer/UNKNOWN_QR_XYZ')
        ->assertStatus(403);
});

it('does not affect guests — middleware silently passes when user is null', function () {
    // Guest unauthenticated request — must not 403 from active check.
    $response = $this->get('/pos/customer/UNKNOWN_QR_XYZ');

    expect($response->status())->not->toBe(403);
});
