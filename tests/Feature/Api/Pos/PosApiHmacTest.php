<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);

    $this->merchant = Merchant::factory()->create([
        'status'   => 'active',
        'category' => 'grocery',
        'tier'     => 'standard',
    ]);
    $this->posUser = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);
    $this->customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'merchant_id' => null,
    ]);

    $this->secret = bin2hex(random_bytes(32));
    $this->plainTextToken = $this->posUser->createToken('terminal', ['pos:write'])->plainTextToken;
    PersonalAccessToken::orderByDesc('id')->first()
        ->forceFill(['hmac_secret' => $this->secret])->save();
});

function saleBody(int $customerId): array
{
    return [
        'customer_id'       => $customerId,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'hmac-r-' . random_int(1000, 9999),
        'use_bonus'         => false,
    ];
}

/**
 * Symfony BrowserKit (Laravel test kernel) reads headers from the $server array
 * with the HTTP_ prefix. Using withHeaders + call($server=[]) loses some
 * headers in this pipeline (Authorization specifically) — passing the full
 * $server array directly is the reliable path for raw-body POSTs.
 */
function postSaleHmac(\Tests\TestCase $t, string $token, string $secret, string $body, int $tsOffset = 0): \Illuminate\Testing\TestResponse
{
    $ts = time() + $tsOffset;
    $sig = hash_hmac('sha256', $ts . '.' . $body, $secret);
    return $t->call('POST', '/api/v1/pos/sale', [], [], [], [
        'HTTP_AUTHORIZATION'     => 'Bearer ' . $token,
        'HTTP_ACCEPT'            => 'application/json',
        'CONTENT_TYPE'           => 'application/json',
        'HTTP_X_PAYLO_TIMESTAMP' => (string) $ts,
        'HTTP_X_PAYLO_SIGNATURE' => 'sha256=' . $sig,
    ], $body);
}

/*
|--------------------------------------------------------------------------
| Happy path
|--------------------------------------------------------------------------
*/

it('accepts a sale with a valid HMAC signature', function () {
    $body = json_encode(saleBody($this->customer->id));
    postSaleHmac($this, $this->plainTextToken, $this->secret, $body)
        ->assertOk()
        ->assertJsonFragment(['idempotent' => false]);
});

/*
|--------------------------------------------------------------------------
| Rejection paths
|--------------------------------------------------------------------------
*/

it('rejects missing HMAC headers when token requires HMAC', function () {
    $this->call('POST', '/api/v1/pos/sale', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainTextToken,
        'HTTP_ACCEPT'        => 'application/json',
        'CONTENT_TYPE'       => 'application/json',
    ], json_encode(saleBody($this->customer->id)))
        ->assertStatus(401)
        ->assertJsonValidationErrors(['X-Paylo-Signature']);
});

it('rejects a tampered body (signature mismatch)', function () {
    $body = json_encode(saleBody($this->customer->id));
    $ts = time();
    $sig = hash_hmac('sha256', $ts . '.' . $body, $this->secret);
    // Tamper the body AFTER signing — what an attacker would do.
    $tampered = str_replace('"sale_amount_cents":5000', '"sale_amount_cents":99999', $body);

    $this->call('POST', '/api/v1/pos/sale', [], [], [], [
        'HTTP_AUTHORIZATION'     => 'Bearer ' . $this->plainTextToken,
        'HTTP_ACCEPT'            => 'application/json',
        'CONTENT_TYPE'           => 'application/json',
        'HTTP_X_PAYLO_TIMESTAMP' => (string) $ts,
        'HTTP_X_PAYLO_SIGNATURE' => 'sha256=' . $sig,
    ], $tampered)
        ->assertStatus(401);
});

it('rejects an expired timestamp (replay protection ±5 min window)', function () {
    $body = json_encode(saleBody($this->customer->id));
    postSaleHmac($this, $this->plainTextToken, $this->secret, $body, tsOffset: -400)
        ->assertStatus(401);
});

it('rejects a future timestamp (clock skew attack)', function () {
    $body = json_encode(saleBody($this->customer->id));
    postSaleHmac($this, $this->plainTextToken, $this->secret, $body, tsOffset: 400)
        ->assertStatus(401);
});

it('rejects malformed X-Paylo-Signature (wrong prefix) with 400', function () {
    $body = json_encode(saleBody($this->customer->id));
    $this->call('POST', '/api/v1/pos/sale', [], [], [], [
        'HTTP_AUTHORIZATION'     => 'Bearer ' . $this->plainTextToken,
        'HTTP_ACCEPT'            => 'application/json',
        'CONTENT_TYPE'           => 'application/json',
        'HTTP_X_PAYLO_TIMESTAMP' => (string) time(),
        'HTTP_X_PAYLO_SIGNATURE' => 'md5=abc123',
    ], $body)
        ->assertStatus(400);
});

it('rejects non-integer timestamp with 400', function () {
    $body = json_encode(saleBody($this->customer->id));
    $this->call('POST', '/api/v1/pos/sale', [], [], [], [
        'HTTP_AUTHORIZATION'     => 'Bearer ' . $this->plainTextToken,
        'HTTP_ACCEPT'            => 'application/json',
        'CONTENT_TYPE'           => 'application/json',
        'HTTP_X_PAYLO_TIMESTAMP' => 'not-a-number',
        'HTTP_X_PAYLO_SIGNATURE' => 'sha256=' . str_repeat('a', 64),
    ], $body)
        ->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| Backward compatibility
|--------------------------------------------------------------------------
*/

it('does not require HMAC when the token has no hmac_secret (legacy tokens)', function () {
    $legacyUser = User::factory()->create([
        'role'        => UserRole::PosTerminal,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);
    $legacyToken = $legacyUser->createToken('legacy', ['pos:write'])->plainTextToken;

    $this->withHeaders([
        'Authorization' => 'Bearer ' . $legacyToken,
        'Accept'        => 'application/json',
    ])->postJson('/api/v1/pos/sale', saleBody($this->customer->id))
        ->assertOk();
});
