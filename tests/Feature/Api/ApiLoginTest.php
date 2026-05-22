<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('api-login:test@example.com|127.0.0.1');
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/login
|--------------------------------------------------------------------------
*/

it('logs in a customer with valid credentials and returns a token + user payload', function () {
    $user = User::factory()->create([
        'email'     => 'test@example.com',
        'password'  => Hash::make('password123'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'test@example.com',
        'password'    => 'password123',
        'device_name' => 'iPhone-test',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'role', 'customer_qr']]);

    expect($response->json('user.id'))->toBe($user->id);
    expect($user->tokens()->where('name', 'iPhone-test')->count())->toBe(1);
});

it('rejects login with wrong password (422 + email error)', function () {
    User::factory()->create([
        'email'    => 'test@example.com',
        'password' => Hash::make('correctpw'),
        'role'     => UserRole::Customer,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'test@example.com',
        'password'    => 'wrongpw',
        'device_name' => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects login with unknown email', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'noone@example.com',
        'password'    => 'whatever',
        'device_name' => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects login for a non-customer role (cashier/admin) — mobile is customer-only', function () {
    User::factory()->create([
        'email'    => 'cashier@example.com',
        'password' => Hash::make('password123'),
        'role'     => UserRole::Cashier,
        'is_active'=> true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'cashier@example.com',
        'password'    => 'password123',
        'device_name' => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects login for an inactive user', function () {
    User::factory()->create([
        'email'    => 'inactive@example.com',
        'password' => Hash::make('password123'),
        'role'     => UserRole::Customer,
        'is_active'=> false,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'inactive@example.com',
        'password'    => 'password123',
        'device_name' => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('requires email, password, device_name on the login payload', function () {
    $response = $this->postJson('/api/v1/auth/login', []);
    $response->assertStatus(422)->assertJsonValidationErrors(['email', 'password', 'device_name']);
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/logout — single token
|--------------------------------------------------------------------------
*/

it('logs out and returns success', function () {
    $user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    Sanctum::actingAs($user, ['customer']);

    $this->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJson(['message' => 'Logged out.']);
    // Note: Sanctum::actingAs doesn't persist a real token row, so we verify
    // contract (200 + message). Real-token revocation is covered indirectly
    // by logout-all (which calls $user->tokens()->delete()) below.
});

it('requires authentication on logout', function () {
    $this->postJson('/api/v1/auth/logout')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/logout-all — all tokens
|--------------------------------------------------------------------------
*/

it('logs out from all devices and deletes every token', function () {
    $user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $user->createToken('phone', ['customer']);
    $user->createToken('tablet', ['customer']);
    Sanctum::actingAs($user, ['customer']);

    $response = $this->postJson('/api/v1/auth/logout-all');

    $response->assertOk()->assertJson(['message' => 'All sessions terminated.']);
    expect($user->refresh()->tokens()->count())->toBe(0);
});

it('requires authentication on logout-all', function () {
    $this->postJson('/api/v1/auth/logout-all')->assertStatus(401);
});
