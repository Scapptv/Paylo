<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 4 — Api-11 (throttle), Usr-1/Api-9 (pagination), M-4 (PII mask).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    RateLimiter::clear('api-login-email:victim@example.com');
});

// ---- Api-11: API login per-email throttle ----

it('Api-11: blocks 11th attempt against same email regardless of IP', function () {
    User::factory()->create([
        'email'     => 'victim@example.com',
        'password'  => bcrypt('correct-horse'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    for ($i = 1; $i <= 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->postJson('/api/v1/auth/login', [
                'email'       => 'victim@example.com',
                'password'    => 'wrong',
                'device_name' => 'phone',
            ])->assertStatus(422);
    }

    // 11-ci IP-dən doğru parol — email-only throttle hələ blokdadır.
    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->postJson('/api/v1/auth/login', [
            'email'       => 'victim@example.com',
            'password'    => 'correct-horse',
            'device_name' => 'phone',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
    expect($response->json('errors.email.0'))->toContain('Çox cəhd');
});

// ---- Usr-1 / Api-9: bucket pagination ----

it('Api-9: wallet returns first 30 buckets with cursor + accurate aggregates', function () {
    $user = User::factory()->create(['role' => UserRole::Customer]);

    // 35 bucket yarat — pagination "has_more" işarələnməlidir.
    for ($i = 0; $i < 35; $i++) {
        $merchant = Merchant::factory()->create(['status' => 'active']);
        Bucket::create([
            'user_id'        => $user->id,
            'merchant_id'    => $merchant->id,
            'balance'        => 100 + $i,
            'earned_total'   => 100 + $i,
            'redeemed_total' => 0,
            'expired_total'  => 0,
        ]);
    }

    Sanctum::actingAs($user, ['customer']);

    $response = $this->getJson('/api/v1/wallet')->assertOk();

    // Pagination yalnız 30 göstərir, lakin aggregate-lər 35-i hesablayır.
    expect($response->json('buckets'))->toHaveCount(30);
    expect($response->json('buckets_count'))->toBe(35);
    expect($response->json('buckets_has_more'))->toBeTrue();
    expect($response->json('buckets_next_cursor'))->not->toBeNull();

    // Cəm balans: 100+101+...+134 = 35 * (100+134) / 2 = 4095
    expect($response->json('total_balance'))->toBe(4095);
});

it('Api-9: wallet has_more=false when buckets fit on one page', function () {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $merchant = Merchant::factory()->create(['status' => 'active']);
    Bucket::create([
        'user_id' => $user->id, 'merchant_id' => $merchant->id,
        'balance' => 500, 'earned_total' => 500, 'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    Sanctum::actingAs($user, ['customer']);

    $response = $this->getJson('/api/v1/wallet')->assertOk();

    expect($response->json('buckets_count'))->toBe(1);
    expect($response->json('buckets_has_more'))->toBeFalse();
    expect($response->json('buckets_next_cursor'))->toBeNull();
});

// ---- M-4: merchant staff PII masking ----

it('M-4: merchant_staff sees masked phone in topCustomers', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $staff    = User::factory()->create([
        'role'        => UserRole::MerchantStaff,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $customer = User::factory()->create([
        'role'      => UserRole::Customer,
        'phone'     => '+994501234567',
        'is_active' => true,
    ]);
    Bucket::create([
        'user_id' => $customer->id, 'merchant_id' => $merchant->id,
        'balance' => 500, 'earned_total' => 500, 'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    $response = $this->actingAs($staff)->get('/merchant/dashboard');
    $response->assertOk();

    $top = $response->viewData('page')['props']['topCustomers'];
    expect($top)->toHaveCount(1);
    // `+994501234567` → `+994******567`: 13 simvol, head 4, tail 3, ortada 6 *.
    expect($top[0]['user']['phone'])->toBe('+994******567');
});

it('M-4: merchant_owner sees full phone in topCustomers', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);
    $owner    = User::factory()->create([
        'role'        => UserRole::MerchantOwner,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $customer = User::factory()->create([
        'role'      => UserRole::Customer,
        'phone'     => '+994501234567',
        'is_active' => true,
    ]);
    Bucket::create([
        'user_id' => $customer->id, 'merchant_id' => $merchant->id,
        'balance' => 500, 'earned_total' => 500, 'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    $response = $this->actingAs($owner)->get('/merchant/dashboard');
    $top      = $response->viewData('page')['props']['topCustomers'];

    expect($top[0]['user']['phone'])->toBe('+994501234567');
});
