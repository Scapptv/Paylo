<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 7.3 — Merchant staff CRUD.
| Yalnız MerchantOwner: list, create, store, edit, update, destroy.
| Staff merchant_id immutable; cross-merchant edit → 404.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->owner    = User::factory()->create([
        'role'        => UserRole::MerchantOwner,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);
});

it('owner sees staff list of own merchant', function () {
    User::factory()->create([
        'role' => UserRole::Cashier, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    User::factory()->create([
        'role' => UserRole::MerchantStaff, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);

    $response = $this->actingAs($this->owner)->get('/merchant/staff');
    $response->assertOk();
    $staff = $response->viewData('page')['props']['staff'];

    expect(count($staff))->toBe(3); // owner + cashier + merchant_staff
});

it('owner creates new cashier with valid payload', function () {
    $this->actingAs($this->owner)->postJson('/merchant/staff', [
        'name'                  => 'Yeni Kassir',
        'email'                 => 'new-cashier@example.com',
        'phone'                 => '+994501234567',
        'password'              => 'strong-password-12',
        'password_confirmation' => 'strong-password-12',
        'role'                  => 'cashier',
        'is_active'             => true,
    ])->assertStatus(302);

    $created = User::where('email', 'new-cashier@example.com')->firstOrFail();
    expect($created->role)->toBe(UserRole::Cashier);
    expect($created->merchant_id)->toBe($this->merchant->id);
    expect($created->is_active)->toBeTrue();
});

it('rejects creating merchant_owner role via staff endpoint (privilege escalation)', function () {
    $this->actingAs($this->owner)->postJson('/merchant/staff', [
        'name'                  => 'Hücum',
        'email'                 => 'attacker@example.com',
        'password'              => 'strong-password-12',
        'password_confirmation' => 'strong-password-12',
        'role'                  => 'merchant_owner',
    ])->assertStatus(422)->assertJsonValidationErrors(['role']);
});

it('rejects creating admin role via staff endpoint', function () {
    $this->actingAs($this->owner)->postJson('/merchant/staff', [
        'name'                  => 'Hücum',
        'email'                 => 'attacker2@example.com',
        'password'              => 'strong-password-12',
        'password_confirmation' => 'strong-password-12',
        'role'                  => 'admin',
    ])->assertStatus(422)->assertJsonValidationErrors(['role']);
});

it('rejects duplicate email on create', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->actingAs($this->owner)->postJson('/merchant/staff', [
        'name'                  => 'X',
        'email'                 => 'taken@example.com',
        'password'              => 'strong-password-12',
        'password_confirmation' => 'strong-password-12',
        'role'                  => 'cashier',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('owner updates staff (name, role, is_active)', function () {
    $staff = User::factory()->create([
        'role' => UserRole::Cashier, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);

    $this->actingAs($this->owner)->put("/merchant/staff/{$staff->id}", [
        'name'      => 'Updated Name',
        'email'     => $staff->email,
        'phone'     => '+994551112233',
        'role'      => 'merchant_staff',
        'is_active' => false,
    ])->assertStatus(302);

    $staff->refresh();
    expect($staff->name)->toBe('Updated Name');
    expect($staff->role)->toBe(UserRole::MerchantStaff);
    expect($staff->is_active)->toBeFalse();
});

it('rejects update attempts to change merchant_id or password', function () {
    $staff = User::factory()->create([
        'role' => UserRole::Cashier, 'merchant_id' => $this->merchant->id,
    ]);

    $this->actingAs($this->owner)->putJson("/merchant/staff/{$staff->id}", [
        'name'        => $staff->name,
        'email'       => $staff->email,
        'role'        => 'cashier',
        'is_active'   => true,
        'merchant_id' => 999,
        'password'    => 'hack',
    ])->assertStatus(422)->assertJsonValidationErrors(['merchant_id', 'password']);
});

it('cross-merchant staff edit returns 404 (no enumeration)', function () {
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);
    $otherStaff    = User::factory()->create([
        'role' => UserRole::Cashier, 'merchant_id' => $otherMerchant->id,
    ]);

    $this->actingAs($this->owner)->get("/merchant/staff/{$otherStaff->id}/edit")
        ->assertStatus(404);
});

it('owner anonymises staff on destroy', function () {
    $staff = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $this->merchant->id,
        'email'       => 'real@example.com',
        'is_active'   => true,
    ]);

    $this->actingAs($this->owner)->delete("/merchant/staff/{$staff->id}")
        ->assertStatus(302);

    $staff->refresh();
    expect($staff->email)->toBe("deleted+{$staff->id}@paylo.deleted");
    expect($staff->is_active)->toBeFalse();
    expect($staff->name)->toContain('Silinmiş');
});

it('cannot destroy a merchant_owner via staff endpoint', function () {
    $coOwner = User::factory()->create([
        'role'        => UserRole::MerchantOwner,
        'merchant_id' => $this->merchant->id,
    ]);

    $this->actingAs($this->owner)->delete("/merchant/staff/{$coOwner->id}")
        ->assertStatus(403);

    expect(User::find($coOwner->id))->not->toBeNull();
});

it('non-owner roles (cashier) blocked from staff endpoints', function () {
    $cashier = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    $this->actingAs($cashier)->get('/merchant/staff')->assertStatus(403);
    $this->actingAs($cashier)->postJson('/merchant/staff', [])->assertStatus(403);
});
