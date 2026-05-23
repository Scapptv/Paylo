<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Modules\Api\Services\RotatingQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /pos/customer/{qr} — QR enumeration-a qarşı sərtləşdirmələr.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $this->merchant->id,
        'is_active'   => true,
    ]);

    // Hər test üçün throttle counter-ini sıfırla (in-process cache).
    RateLimiter::clear(sha1($this->cashier->id . '|' . '127.0.0.1'));
    cache()->flush();
});

it('returns a uniform 200 response with the SAME structure for unknown QR (no enumeration via 404)', function () {
    $response = $this->actingAs($this->cashier)->getJson('/pos/customer/nonexistent_qr_xyz');

    $response->assertOk()
        ->assertExactJson([
            'status'   => 'not_found',
            'customer' => null,
            'bucket'   => null,
        ]);

    // 404 qaytarmır — attacker 200 vs 404 fərqi ilə brute-force edə bilməz.
    $this->assertNotEquals(404, $response->status());
});

it('returns ok status with customer + bucket payload for a known QR', function () {
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'customer_qr' => 'q_known_abc',
    ]);

    $response = $this->actingAs($this->cashier)->getJson('/pos/customer/q_known_abc');

    // Audit P-2: static `customer_qr` cashier-ə qaytarılmır. Yalnız `id` və `name`.
    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
            'customer' => [
                'id' => $customer->id,
            ],
            'bucket' => [
                'balance'        => 0,
                'earned_total'   => 0,
                'redeemed_total' => 0,
            ],
        ])
        ->assertJsonMissingPath('customer.qr');
});

it('writes an audit log entry without leaking the raw QR (sha256 hash only)', function () {
    Log::spy();

    $rawQr = 'q_secret_raw_value_42';
    $this->actingAs($this->cashier)->getJson('/pos/customer/' . $rawQr);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context) use ($rawQr) {
            // Raw QR-ın hər hansı bir yerdə plain-text olaraq olmadığını yoxla.
            $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

            return $message === 'pos.customer.lookup'
                && $context['merchant_id'] === $this->merchant->id
                && $context['cashier_id']  === $this->cashier->id
                && $context['status']      === 'not_found'
                && $context['qr_hash']     === hash('sha256', $rawQr)
                && ! str_contains($serialized, $rawQr);
        })
        ->once();
});

it('throttles lookups beyond 30 requests per minute (returns 429)', function () {
    // 30 sorğu icazəlidir, 31-ci rate-limit-i tetikləməlidir.
    for ($i = 0; $i < 30; $i++) {
        $r = $this->actingAs($this->cashier)->getJson('/pos/customer/qr_' . $i);
        $r->assertOk();
    }

    $response = $this->actingAs($this->cashier)->getJson('/pos/customer/qr_overflow');

    $response->assertStatus(429);
});

/*
|--------------------------------------------------------------------------
| Rotating QR token (canonical mobil axını) — qr1.{user_qr}.{exp}.{hmac16}
|--------------------------------------------------------------------------
*/

it('resolves a valid rotating QR token to the customer (canonical mobile flow)', function () {
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'customer_qr' => 'q_rotating_user',
    ]);

    $token = app(RotatingQrService::class)->generate($customer)['token'];

    $response = $this->actingAs($this->cashier)->getJson('/pos/customer/' . $token);

    // Audit P-2: static `customer_qr` rotating axında belə cashier-ə qaytarılmır.
    $response->assertOk()->assertJson([
        'status'   => 'ok',
        'customer' => ['id' => $customer->id],
    ])->assertJsonMissingPath('customer.qr');
});

it('rejects an expired rotating QR token with not_found (uniform shape)', function () {
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'customer_qr' => 'q_expired_user',
    ]);

    // TTL = 1s, sonra Carbon-u qabaqlayaraq expire et.
    $token = app(RotatingQrService::class)->generate($customer, 1)['token'];
    \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(5));

    $response = $this->actingAs($this->cashier)->getJson('/pos/customer/' . $token);

    $response->assertOk()->assertExactJson([
        'status'   => 'not_found',
        'customer' => null,
        'bucket'   => null,
    ]);

    \Illuminate\Support\Carbon::setTestNow();
});

it('rejects a tampered rotating QR token (HMAC mismatch)', function () {
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'customer_qr' => 'q_tamper_user',
    ]);

    $token   = app(RotatingQrService::class)->generate($customer)['token'];
    // Son 16 hex hmac-i pozaq.
    $tampered = substr($token, 0, -16) . str_repeat('0', 16);

    $response = $this->actingAs($this->cashier)->getJson('/pos/customer/' . $tampered);

    $response->assertOk()->assertJsonPath('status', 'not_found');
});

it('blocks replay of a rotating QR token after first successful lookup', function () {
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'customer_qr' => 'q_replay_user',
    ]);

    $token = app(RotatingQrService::class)->generate($customer)['token'];

    $first = $this->actingAs($this->cashier)->getJson('/pos/customer/' . $token);
    $first->assertOk()->assertJsonPath('status', 'ok');

    // Eyni token-i ikinci dəfə → replay → not_found.
    $second = $this->actingAs($this->cashier)->getJson('/pos/customer/' . $token);
    $second->assertOk()->assertJsonPath('status', 'not_found');
});
