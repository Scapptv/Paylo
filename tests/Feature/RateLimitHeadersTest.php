<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 8 T-1 — `X-RateLimit-*` header-ləri API cavabında.
| Mobile client proaktiv backoff edə bilsin.
|--------------------------------------------------------------------------
*/

it('adds X-RateLimit-Limit and X-RateLimit-Remaining to API response', function () {
    $user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    Sanctum::actingAs($user, ['customer']);

    $response = $this->getJson('/api/v1/wallet');
    $response->assertOk();
    $response->assertHeader('X-RateLimit-Limit');
    $response->assertHeader('X-RateLimit-Remaining');
});

it('X-RateLimit-Remaining is a non-negative integer string', function () {
    $user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    Sanctum::actingAs($user, ['customer']);

    $response = $this->getJson('/api/v1/wallet');
    $remaining = (int) $response->headers->get('X-RateLimit-Remaining');
    $limit     = (int) $response->headers->get('X-RateLimit-Limit');

    expect($remaining)->toBeGreaterThanOrEqual(0);
    expect($remaining)->toBeLessThanOrEqual($limit);
});

/*
|--------------------------------------------------------------------------
| Audit 2026-06-04 API-1 — X-RateLimit-Limit route throttle-ını əks etdirsin,
| hardcoded 60-ı yox. Əvvəllər AddRateLimitHeaders bütün route-ları 60 göstərirdi.
|--------------------------------------------------------------------------
*/

it('API-1: /qr (throttle:10) advertises limit 10, not the hardcoded 60', function () {
    $user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    Sanctum::actingAs($user, ['customer']);

    // /api/v1/qr route-u `throttle:10,1` ilə qorunur. Köhnə kodda
    // AddRateLimitHeaders bunu hardcoded 60 ilə əzirdi — indi route-un düzgün
    // dəyəri (10) qalır, mobil M-7 parser düzgün backoff edə bilir.
    $response = $this->getJson('/api/v1/qr')->assertOk();
    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(10);
});

it('API-1: /wallet (throttle:60) advertises limit 60 from the route throttle', function () {
    $user = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    Sanctum::actingAs($user, ['customer']);

    $response = $this->getJson('/api/v1/wallet')->assertOk();
    expect((int) $response->headers->get('X-RateLimit-Limit'))->toBe(60);
});
