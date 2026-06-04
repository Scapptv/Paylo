<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->posUser  = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);
});

it('rejects POS API requests without a token (401)', function () {
    $this->postJson('/api/v1/pos/customer/lookup', ['qr' => 'qr_anything'])
        ->assertStatus(401);

    $this->postJson('/api/v1/pos/sale/preview', [])
        ->assertStatus(401);

    $this->postJson('/api/v1/pos/sale', [])
        ->assertStatus(401);
});

it('rejects POS API requests when token has only customer ability (403)', function () {
    // Customer token-i ilə POS endpoint-ə çatmaq cəhdi — ability:pos:write blok edir.
    Sanctum::actingAs($this->posUser, abilities: ['customer']);

    $this->postJson('/api/v1/pos/customer/lookup', ['qr' => 'qr_anything'])
        ->assertStatus(403);
});

it('allows POS API requests when token has pos:write ability', function () {
    Sanctum::actingAs($this->posUser, abilities: ['pos:write']);

    // Lookup heç olmasa 200 (status=not_found) qaytarmalıdır — auth+ability keçdi.
    $this->postJson('/api/v1/pos/customer/lookup', ['qr' => 'qr_unknown_xxx'])
        ->assertOk()
        ->assertJson(['status' => 'not_found']);
});

it('fails fast (500) when pos:write token belongs to a user without merchant_id', function () {
    // Defensive — IssuePosTokenCommand bunu yaratmamalıdır, lakin əgər kimsə əl ilə
    // tampered token yaradarsa, controller fail-fast etsin (silent success deyil).
    $orphanUser = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => null,
        'is_active'   => true,
    ]);

    Sanctum::actingAs($orphanUser, abilities: ['pos:write']);

    $this->postJson('/api/v1/pos/customer/lookup', ['qr' => 'qr_x'])
        ->assertStatus(500);
});

it('blocks inactive POS users via EnsureUserIsActive middleware', function () {
    $this->posUser->update(['is_active' => false]);

    Sanctum::actingAs($this->posUser, abilities: ['pos:write']);

    // EnsureUserIsActive 403 və ya logout response qaytarır — hər halda 200 olmaz.
    $this->postJson('/api/v1/pos/customer/lookup', ['qr' => 'qr_x'])
        ->assertStatus(403);
});
