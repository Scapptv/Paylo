<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Admin roadmap Phase 2.2 — Users idarəetməsi (siyahı + filter + toggle).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $this->merchant = Merchant::factory()->create(['status' => 'active', 'code' => 'ALP']);
});

it('Phase 2.2: renders the users list', function () {
    User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $this->actingAs($this->admin)->get('/admin/users')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users')
            ->has('users.data')
            ->has('roles')
            ->where('authId', $this->admin->id)
        );
});

it('Phase 2.2: filters by role', function () {
    User::factory()->count(2)->create(['role' => UserRole::Customer, 'is_active' => true]);
    User::factory()->create(['role' => UserRole::Cashier, 'merchant_id' => $this->merchant->id, 'is_active' => true]);

    // admin (beforeEach) + cashier xaric → yalnız 2 customer.
    $this->actingAs($this->admin)->get('/admin/users?role=customer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 2));
});

it('Phase 2.2: filters by active status', function () {
    User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]);

    // Yalnız 1 deaktiv (admin aktivdir).
    $this->actingAs($this->admin)->get('/admin/users?active=0')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));
});

it('Phase 2.2: filters by q (name/email)', function () {
    User::factory()->create(['role' => UserRole::Customer, 'name' => 'Findme Person', 'is_active' => true]);

    $this->actingAs($this->admin)->get('/admin/users?q=findme')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));
});

it('Phase 2.2: deactivates an active user and revokes tokens', function () {
    $target = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $target->createToken('mobile');
    expect($target->tokens()->count())->toBe(1);

    $this->from('/admin/users')
        ->actingAs($this->admin)
        ->post('/admin/users/' . $target->id . '/toggle-active')
        ->assertRedirect('/admin/users')
        ->assertSessionHas('success');

    expect($target->fresh()->is_active)->toBeFalse();
    expect($target->tokens()->count())->toBe(0);
});

it('Phase 2.2: reactivates an inactive user', function () {
    $target = User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]);

    $this->from('/admin/users')
        ->actingAs($this->admin)
        ->post('/admin/users/' . $target->id . '/toggle-active')
        ->assertRedirect('/admin/users')
        ->assertSessionHas('success');

    expect($target->fresh()->is_active)->toBeTrue();
});

it('Phase 2.2: admin cannot toggle own account', function () {
    $this->from('/admin/users')
        ->actingAs($this->admin)
        ->post('/admin/users/' . $this->admin->id . '/toggle-active')
        ->assertRedirect('/admin/users')
        ->assertSessionHas('error');

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('Phase 2.2: blocks non-admin from list', function () {
    $owner = User::factory()->create(['role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true]);

    $this->actingAs($owner)->get('/admin/users')->assertStatus(403);
});

it('Phase 2.2: blocks non-admin from toggle', function () {
    $owner = User::factory()->create(['role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true]);
    $target = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $this->actingAs($owner)->post('/admin/users/' . $target->id . '/toggle-active')->assertStatus(403);
    expect($target->fresh()->is_active)->toBeTrue();
});
