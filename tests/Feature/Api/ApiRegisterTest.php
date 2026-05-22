<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| POST /api/v1/auth/register
|--------------------------------------------------------------------------
*/

it('registers a new customer and returns a sanctum token + 201', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni İstifadəçi',
        'email'                 => 'new@example.com',
        'phone'                 => '+994501234567',
        'password'              => 'strong-password-123',
        'password_confirmation' => 'strong-password-123',
        'device_name'           => 'iPhone-test',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'customer_qr']]);

    $user = User::where('email', 'new@example.com')->firstOrFail();
    expect($user->role)->toBe(UserRole::Customer);
    expect($user->is_active)->toBeTrue();
    expect($user->customer_qr)->toStartWith('qr_')->toHaveLength(15); // "qr_" + 12 chars
});

it('rejects duplicate email registration', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni',
        'email'                 => 'taken@example.com',
        'phone'                 => '+994501234567',
        'password'              => 'strong-password-123',
        'password_confirmation' => 'strong-password-123',
        'device_name'           => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('rejects weak password (< 8 chars)', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni',
        'email'                 => 'weak@example.com',
        'phone'                 => '+994501234567',
        'password'              => 'short',
        'password_confirmation' => 'short',
        'device_name'           => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['password']);
});

it('rejects password confirmation mismatch', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni',
        'email'                 => 'mismatch@example.com',
        'phone'                 => '+994501234567',
        'password'              => 'strong-password-123',
        'password_confirmation' => 'different-confirmation',
        'device_name'           => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['password']);
});

it('requires all mandatory fields', function () {
    $response = $this->postJson('/api/v1/auth/register', []);

    $response->assertStatus(422)->assertJsonValidationErrors([
        'name', 'email', 'phone', 'password', 'device_name',
    ]);
});

it('rejects invalid email format', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni',
        'email'                 => 'not-an-email',
        'phone'                 => '+994501234567',
        'password'              => 'strong-password-123',
        'password_confirmation' => 'strong-password-123',
        'device_name'           => 'iPhone-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
});
