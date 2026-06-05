<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use App\Modules\Api\Services\RotatingQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_abc123def456',
    ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/v1/qr — rotating token
|--------------------------------------------------------------------------
*/

it('issues a rotating qr token with expected shape', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $response = $this->getJson('/api/v1/qr');

    // Audit Api-5: `static_qr` field-i response-dan çıxarıldı. Mobile app
    // bu dəyəri login/me cavabından lokal saxlamalıdır, hər rotation-da yox.
    $response->assertOk()
        ->assertJsonStructure(['qr_value', 'expires_at', 'ttl'])
        ->assertJsonMissingPath('static_qr');

    expect($response->json('qr_value'))->toStartWith('qr1.');
    expect($response->json('ttl'))->toBe(30);
});

it('the issued token verifies via RotatingQrService', function () {
    Sanctum::actingAs($this->user, ['customer']);

    $token  = $this->getJson('/api/v1/qr')->json('qr_value');
    $result = app(RotatingQrService::class)->verify($token);

    expect($result['valid'])->toBeTrue();
    expect($result['user_qr'])->toBe($this->user->customer_qr);
});

it('requires authentication on /qr', function () {
    $this->getJson('/api/v1/qr')->assertStatus(401);
});
