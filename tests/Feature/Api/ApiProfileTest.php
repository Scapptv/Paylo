<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\PushToken;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'name'      => 'Aysel',
        'email'     => 'aysel@example.com',
        'phone'     => '+994501112233',
        'password'  => Hash::make('current-password'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/me
|--------------------------------------------------------------------------
*/

it('returns the authenticated user profile', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJson([
            'user' => [
                'id'    => $this->user->id,
                'name'  => 'Aysel',
                'email' => 'aysel@example.com',
                'role'  => 'customer',
            ],
        ]);
});

it('requires authentication on GET /me', function () {
    $this->getJson('/api/v1/me')->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| PUT /api/v1/me
|--------------------------------------------------------------------------
*/

it('updates name and phone', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->putJson('/api/v1/me', [
        'name'  => 'Aysel Yeniadlı',
        'phone' => '+994559998877',
    ])->assertOk()->assertJson(['user' => ['name' => 'Aysel Yeniadlı', 'phone' => '+994559998877']]);

    $this->user->refresh();
    expect($this->user->name)->toBe('Aysel Yeniadlı');
    expect($this->user->phone)->toBe('+994559998877');
});

it('rejects invalid locale on update', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->putJson('/api/v1/me', ['locale' => 'jp'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['locale']);
});

it('accepts valid locale value (az/en/ru) without error', function () {
    Sanctum::actingAs($this->user, ['customer']);
    $this->putJson('/api/v1/me', ['locale' => 'en'])->assertOk();
});

/*
|--------------------------------------------------------------------------
| PUT /api/v1/me/password
|--------------------------------------------------------------------------
*/

it('changes password when current password is correct', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->putJson('/api/v1/me/password', [
        'current_password'      => 'current-password',
        'password'              => 'brand-new-secret-99',
        'password_confirmation' => 'brand-new-secret-99',
    ])->assertOk()->assertJson(['message' => 'Şifrə yeniləndi.']);

    expect(Hash::check('brand-new-secret-99', $this->user->refresh()->password))->toBeTrue();
});

it('rejects password change with wrong current password', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->putJson('/api/v1/me/password', [
        'current_password'      => 'wrong',
        'password'              => 'brand-new-secret-99',
        'password_confirmation' => 'brand-new-secret-99',
    ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
});

it('rejects password change when confirmation mismatches', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->putJson('/api/v1/me/password', [
        'current_password'      => 'current-password',
        'password'              => 'brand-new-secret-99',
        'password_confirmation' => 'different',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

it('rejects password change when new password too short', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->putJson('/api/v1/me/password', [
        'current_password'      => 'current-password',
        'password'              => 'short',
        'password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});

/*
|--------------------------------------------------------------------------
| DELETE /api/v1/me — GDPR anonymise
|--------------------------------------------------------------------------
*/

it('deletes (anonymises) the account with correct password and confirm flag', function () {
    PushToken::create([
        'user_id' => $this->user->id, 'token' => 'tok-1', 'platform' => 'ios', 'last_seen_at' => now(),
    ]);
    Sanctum::actingAs($this->user, ['customer']);

    $this->deleteJson('/api/v1/me', [
        'password' => 'current-password',
        'confirm'  => true,
    ])->assertOk()->assertJson(['message' => 'Hesab silindi.']);

    $this->user->refresh();
    expect($this->user->name)->toStartWith('Silinmiş istifadəçi #');
    expect($this->user->email)->toBe("deleted+{$this->user->id}@paylo.deleted");
    expect($this->user->phone)->toBeNull();
    expect($this->user->is_active)->toBeFalse();
    expect($this->user->customer_qr)->toBeNull();
    expect($this->user->pushTokens()->count())->toBe(0);
    expect($this->user->tokens()->count())->toBe(0);
});

it('rejects account deletion with wrong password', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->deleteJson('/api/v1/me', [
        'password' => 'wrong',
        'confirm'  => true,
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);

    expect($this->user->refresh()->is_active)->toBeTrue();
});

it('rejects account deletion without confirm flag', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->deleteJson('/api/v1/me', [
        'password' => 'current-password',
        'confirm'  => false,
    ])->assertStatus(422)->assertJsonValidationErrors(['confirm']);
});
