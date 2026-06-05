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

it('returns generic accepted response for duplicate email (Audit Api-3, no enumeration)', function () {
    // Audit Api-3: əvvəlki 422 + Rule::unique mesajı email enumeration imkanı verirdi
    // ("bu email artıq var" → attacker email yoxlaması). İndi hər iki halda (mövcud /
    // yeni) eyni 200 OK + generic message; mövcud user üçün token verilmir, real
    // create baş vermir, yalnız audit log yazılır.
    User::factory()->create([
        'email' => 'taken@example.com',
        'role'  => UserRole::Customer,
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni',
        'email'                 => 'taken@example.com',
        'phone'                 => '+994501234567',
        'password'              => 'strong-password-123',
        'password_confirmation' => 'strong-password-123',
        'device_name'           => 'iPhone-test',
    ]);

    $response->assertOk()
        ->assertJsonPath('registered', false)
        ->assertJsonPath('token', null)
        ->assertJsonPath('user', null);

    // Mövcud user yenilənməyib.
    expect(User::where('email', 'taken@example.com')->count())->toBe(1);
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

// Audit Api-8: phone E.164 yaxın regex (`^\+?\d{6,15}$`).
it('rejects phone with letters or punctuation', function () {
    foreach (['abc12345', '+994 50 123 45 67', '+994-50-1234567', '050.1234567', ''] as $bad) {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Yeni',
            'email'                 => 'unique-' . uniqid() . '@example.com',
            'phone'                 => $bad,
            'password'              => 'strong-password-123',
            'password_confirmation' => 'strong-password-123',
            'device_name'           => 'iPhone-test',
        ])->assertStatus(422)->assertJsonValidationErrors(['phone']);
    }
});

it('rejects phone shorter than 6 digits', function () {
    $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni',
        'email'                 => 'short-phone@example.com',
        'phone'                 => '+1234',
        'password'              => 'strong-password-123',
        'password_confirmation' => 'strong-password-123',
        'device_name'           => 'iPhone-test',
    ])->assertStatus(422)->assertJsonValidationErrors(['phone']);
});

it('accepts E.164 phone with and without leading plus', function () {
    foreach (['994501234567', '+994501234567'] as $i => $ok) {
        $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Yeni',
            'email'                 => "ok-{$i}@example.com",
            'phone'                 => $ok,
            'password'              => 'strong-password-123',
            'password_confirmation' => 'strong-password-123',
            'device_name'           => 'iPhone-test',
        ])->assertSuccessful();
    }
});
