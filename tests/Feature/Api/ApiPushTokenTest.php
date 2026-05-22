<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\PushToken;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
});

/*
|--------------------------------------------------------------------------
| POST /api/v1/push/register
|--------------------------------------------------------------------------
*/

it('registers a new push token for the authenticated user', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->postJson('/api/v1/push/register', [
        'token'        => 'fcm-token-abc',
        'platform'     => 'android',
        'app_version'  => '1.0.0',
        'device_model' => 'Pixel 8',
    ])->assertOk()->assertJson(['message' => 'Push token registered.']);

    expect(PushToken::where('user_id', $this->user->id)->where('token', 'fcm-token-abc')->count())->toBe(1);
});

it('upserts an existing token for the same user (no duplicates)', function () {
    PushToken::create([
        'user_id' => $this->user->id, 'token' => 'fcm-1', 'platform' => 'ios', 'last_seen_at' => now()->subDay(),
    ]);
    Sanctum::actingAs($this->user, ['customer']);

    $this->postJson('/api/v1/push/register', [
        'token' => 'fcm-1', 'platform' => 'ios', 'app_version' => '2.0.0',
    ])->assertOk();

    $row = PushToken::where('token', 'fcm-1')->first();
    expect(PushToken::where('token', 'fcm-1')->count())->toBe(1);
    expect($row->app_version)->toBe('2.0.0');
});

it('hijacks-prevention: takes over a token from another user (by design)', function () {
    // Bu davranış qəsdidir — token başqasının cihazına bağlıdırsa,
    // onun bağlamasını silirik (token bilməklə kanal ələ keçirilməsin deyə).
    $other = User::factory()->create(['role' => UserRole::Customer]);
    PushToken::create([
        'user_id' => $other->id, 'token' => 'fcm-shared', 'platform' => 'ios', 'last_seen_at' => now(),
    ]);

    Sanctum::actingAs($this->user, ['customer']);

    $this->postJson('/api/v1/push/register', [
        'token' => 'fcm-shared', 'platform' => 'android',
    ])->assertOk();

    expect(PushToken::where('user_id', $other->id)->where('token', 'fcm-shared')->count())->toBe(0);
    expect(PushToken::where('user_id', $this->user->id)->where('token', 'fcm-shared')->count())->toBe(1);
});

it('rejects invalid platform', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $this->postJson('/api/v1/push/register', [
        'token' => 'tok', 'platform' => 'windows',
    ])->assertStatus(422)->assertJsonValidationErrors(['platform']);
});

it('requires authentication on /push/register', function () {
    $this->postJson('/api/v1/push/register', [
        'token' => 'tok', 'platform' => 'ios',
    ])->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| DELETE /api/v1/push/register
|--------------------------------------------------------------------------
*/

it('deletes own push token', function () {
    PushToken::create([
        'user_id' => $this->user->id, 'token' => 'fcm-del', 'platform' => 'ios', 'last_seen_at' => now(),
    ]);
    Sanctum::actingAs($this->user, ['customer']);

    $this->deleteJson('/api/v1/push/register', ['token' => 'fcm-del'])->assertOk();

    expect(PushToken::where('token', 'fcm-del')->count())->toBe(0);
});

it('does not delete another user\'s token', function () {
    $other = User::factory()->create(['role' => UserRole::Customer]);
    PushToken::create([
        'user_id' => $other->id, 'token' => 'fcm-others', 'platform' => 'ios', 'last_seen_at' => now(),
    ]);
    Sanctum::actingAs($this->user, ['customer']);

    $this->deleteJson('/api/v1/push/register', ['token' => 'fcm-others'])->assertOk();

    expect(PushToken::where('user_id', $other->id)->where('token', 'fcm-others')->count())->toBe(1);
});

it('requires authentication on DELETE /push/register', function () {
    $this->deleteJson('/api/v1/push/register', ['token' => 'tok'])->assertStatus(401);
});
