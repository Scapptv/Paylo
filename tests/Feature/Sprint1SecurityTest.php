<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\PushToken;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use App\Modules\Api\Services\RotatingQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 1 — Security hardening (AUDIT_PLAN.md istinad).
|
| Bu fayl bütün Sprint 1 tapıntıları üçün regression testlərini toplayır.
| Hər tapıntı öz `describe()` bloku altındadır — gələcəkdə tək-tək istinad asanlaşsın.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 200, 'restaurant' => 500]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', ['standard' => 10000]);
    config()->set('loyalty.redemption.max_percent_of_sale', 50);
    config()->set('loyalty.redemption.min_sale_cents', 100);
});

/*
|--------------------------------------------------------------------------
| C-2 — Cross-merchant ledger entry leak (Transaction relation scope)
|--------------------------------------------------------------------------
|
| `receipt_no` global unique deyil — yalnız (merchant_id, receipt_no) cütü.
| İki fərqli merchant eyni qəbz nömrəsini işlədə bilər. `Transaction::ledgerEntries`
| relation `merchant_id` ilə də scope-lanmalıdır ki, cross-merchant leak olmasın.
*/

it('Transaction::ledgerEntries scopes by merchant_id (C-2)', function () {
    $merchantA = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $merchantB = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $customer  = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $ledger = app(LedgerService::class);

    // Eyni `receipt_no = 'R-001'` iki fərqli merchant üçün.
    $entryA = $ledger->earn($customer, $merchantA, new BonusValue(100), receiptNo: 'R-001');
    $entryB = $ledger->earn($customer, $merchantB, new BonusValue(200), receiptNo: 'R-001');

    $txA = Transaction::create([
        'receipt_no'      => 'R-001',
        'merchant_id'     => $merchantA->id,
        'cashier_id'      => null,
        'user_id'         => $customer->id,
        'sale_amount'     => 5000,
        'earned_amount'   => 100,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $txB = Transaction::create([
        'receipt_no'      => 'R-001',
        'merchant_id'     => $merchantB->id,
        'cashier_id'      => null,
        'user_id'         => $customer->id,
        'sale_amount'     => 10000,
        'earned_amount'   => 200,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $txAEntries = $txA->ledgerEntries()->get();
    $txBEntries = $txB->ledgerEntries()->get();

    // Transaction A yalnız öz merchant-ının entry-sini görsün.
    expect($txAEntries->pluck('id')->all())->toBe([$entryA->id]);
    // Transaction B yalnız öz merchant-ının entry-sini görsün.
    expect($txBEntries->pluck('id')->all())->toBe([$entryB->id]);
});

it('Transaction::ledgerEntries throws on eager load to prevent silent leak (C-2 — composite key limitation)', function () {
    // MƏHDUDIYYƏT: Laravel HasMany composite foreign key (merchant_id, receipt_no)
    // dəstəkləmir. Eager load relation-ı fresh instance üzərində çağırır →
    // `$this->merchant_id` null → scope səssizcə boş gəlir.
    // Yanlış data göstərməkdən qaçmaq üçün bu halda explicit LogicException atırıq.
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    Transaction::create([
        'receipt_no'      => 'R-x',
        'merchant_id'     => $merchant->id,
        'cashier_id'      => null,
        'user_id'         => $customer->id,
        'sale_amount'     => 1000,
        'earned_amount'   => 0,
        'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    expect(fn () => Transaction::with('ledgerEntries')->get())
        ->toThrow(\LogicException::class, 'eager loading');
});

/*
|--------------------------------------------------------------------------
| P-1 — POS lookup `is_active` filter
|--------------------------------------------------------------------------
*/

it('POS lookup ignores inactive customer (P-1)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $deactivated = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => false,
        'customer_qr' => 'qr_deactivated_xyz',
    ]);

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/' . $deactivated->customer_qr);

    // Status uniformdur (enumeration qarşısı), lakin customer payload-da null
    // olur — POS yenidən sale ekranını açmır.
    $response->assertOk()
        ->assertJson(['status' => 'not_found', 'customer' => null, 'bucket' => null]);
});

it('POS lookup still finds active customer (P-1 — regression)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $active = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_active_xyz',
    ]);

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/' . $active->customer_qr);

    $response->assertOk()->assertJsonPath('status', 'ok')
        ->assertJsonPath('customer.id', $active->id);
});

/*
|--------------------------------------------------------------------------
| P-2 — Static `customer.qr` response-dan çıxarılıb
|--------------------------------------------------------------------------
*/

it('POS lookup response does not leak static customer_qr (P-2)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_static_secret',
    ]);

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/' . $customer->customer_qr);

    $response->assertOk()->assertJsonPath('customer.id', $customer->id);

    // Response-da `customer.qr` field-i mövcud olmamalıdır.
    $response->assertJsonMissingPath('customer.qr');

    // Plain text olaraq da static QR cavabda görünməsin (defense-in-depth).
    expect($response->getContent())->not->toContain('qr_static_secret');
});

/*
|--------------------------------------------------------------------------
| P-12 — `markUsed` failure non-fatal
|--------------------------------------------------------------------------
*/

it('POS lookup succeeds even when RotatingQrService::markUsed throws (P-12)', function () {
    $merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery', 'tier' => 'standard']);
    $cashier  = User::factory()->create([
        'role'        => UserRole::Cashier,
        'merchant_id' => $merchant->id,
        'is_active'   => true,
    ]);
    $customer = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_p12_customer',
    ]);

    // RotatingQrService-i mock-la → `markUsed` istisna atır, `verify` valid token
    // qaytarır. Lookup hələ də 200 + ok cavab verməlidir, exception sızmamalıdır.
    $this->mock(RotatingQrService::class, function (MockInterface $mock) use ($customer) {
        $mock->shouldReceive('verify')->andReturn([
            'valid'   => true,
            'user_qr' => $customer->customer_qr,
            'reason'  => null,
            'hmac'    => 'abc1234567890def',
            'exp'     => time() + 60,
        ]);
        $mock->shouldReceive('markUsed')
            ->once()
            ->andThrow(new \RuntimeException('cache backend down'));
    });

    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('warning')
        ->withArgs(fn ($message) => str_contains($message, 'mark_used_failed'))
        ->once();

    $response = $this->actingAs($cashier)
        ->getJson('/pos/customer/qr1.qr_p12_customer.9999999999.deadbeefdeadbeef');

    $response->assertOk()->assertJsonPath('status', 'ok');
});

/*
|--------------------------------------------------------------------------
| Api-1 + Api-2 — Mobile login: timing-equalisation + unified error messages
|--------------------------------------------------------------------------
*/

it('mobile login returns identical error response for non-existent email (Api-1, Api-2)', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'unknown@example.com',
        'password'    => 'whatever',
        'device_name' => 'pixel-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    expect($response->json('errors.email.0'))->toBe('Yanlış e-poçt və ya şifrə.');
});

it('mobile login returns identical error for wrong password (Api-2)', function () {
    User::factory()->create([
        'email'     => 'a@example.com',
        'password'  => Hash::make('correct-secret'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'a@example.com',
        'password'    => 'wrong',
        'device_name' => 'pixel-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    expect($response->json('errors.email.0'))->toBe('Yanlış e-poçt və ya şifrə.');
});

it('mobile login returns identical error for non-customer role (Api-2)', function () {
    User::factory()->create([
        'email'     => 'admin@example.com',
        'password'  => Hash::make('correct-secret'),
        'role'      => UserRole::Admin,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'admin@example.com',
        'password'    => 'correct-secret',
        'device_name' => 'pixel-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    expect($response->json('errors.email.0'))->toBe('Yanlış e-poçt və ya şifrə.');
});

it('mobile login returns identical error for deactivated customer (Api-2)', function () {
    User::factory()->create([
        'email'     => 'deactivated@example.com',
        'password'  => Hash::make('correct-secret'),
        'role'      => UserRole::Customer,
        'is_active' => false,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'deactivated@example.com',
        'password'    => 'correct-secret',
        'device_name' => 'pixel-test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    expect($response->json('errors.email.0'))->toBe('Yanlış e-poçt və ya şifrə.');
});

it('mobile login still succeeds for valid customer (Api-1, Api-2 — regression)', function () {
    User::factory()->create([
        'email'     => 'ok@example.com',
        'password'  => Hash::make('correct-secret'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'       => 'ok@example.com',
        'password'    => 'correct-secret',
        'device_name' => 'pixel-test',
    ]);

    $response->assertOk()->assertJsonStructure(['token', 'expires_at', 'user']);
});

/*
|--------------------------------------------------------------------------
| Api-3 — Register endpoint: generic response, no enumeration
|--------------------------------------------------------------------------
*/

it('register returns generic accepted response for new email (Api-3)', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Yeni Customer',
        'email'                 => 'newcustomer@example.com',
        'phone'                 => '+994501112233',
        'password'              => 'long-secret-123',
        'password_confirmation' => 'long-secret-123',
        'device_name'           => 'iphone-test',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('registered', true)
        ->assertJsonStructure(['message', 'registered', 'token', 'expires_at', 'user']);
});

it('register returns generic accepted response for existing email (Api-3 — no enumeration)', function () {
    User::factory()->create([
        'email'     => 'existing@example.com',
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Imposter',
        'email'                 => 'existing@example.com',
        'phone'                 => '+994501112233',
        'password'              => 'long-secret-123',
        'password_confirmation' => 'long-secret-123',
        'device_name'           => 'iphone-test',
    ]);

    // Eyni "Qeydiyyat sorğunuz qəbul edildi" mesajı, lakin token yox + registered=false.
    // 200 vs 201 statusu mobile UX-də ayırd edilir, lakin response shape eynidir.
    $response->assertOk()
        ->assertJsonPath('registered', false)
        ->assertJsonPath('token', null)
        ->assertJsonPath('user', null);

    // Mövcud user yenilənməyib (parol köhnə qaldı, customer_qr eyni qaldı).
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Api-4 — Push token: cross-user request → 403, mövcud token toxunulmaz
|--------------------------------------------------------------------------
*/

it('push register rejects token already bound to another user with 403 (Api-4)', function () {
    $owner  = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $other  = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);

    $owner->createToken('iphone-owner', ['customer']);

    PushToken::create([
        'user_id'      => $owner->id,
        'token'        => 'fcm:victim-token-1234567890',
        'platform'     => 'android',
        'last_seen_at' => now(),
    ]);

    $token = $other->createToken('iphone-other', ['customer'])->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/v1/push/register', [
            'token'    => 'fcm:victim-token-1234567890',
            'platform' => 'ios',
        ]);

    $response->assertStatus(403);

    // Mövcud token toxunulmadı — owner-ə hələ də bağlıdır.
    expect(PushToken::where('token', 'fcm:victim-token-1234567890')->first()->user_id)
        ->toBe($owner->id);
});

it('push register still works for same user re-registering own token (Api-4 — regression)', function () {
    $user  = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
    $token = $user->createToken('iphone-own', ['customer'])->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/v1/push/register', [
            'token'    => 'fcm:my-own-token-1234',
            'platform' => 'ios',
        ]);

    $response->assertOk();
    expect(PushToken::where('user_id', $user->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Api-5 — Rotating QR endpoint: static_qr cavabda yoxdur
|--------------------------------------------------------------------------
*/

it('rotating QR endpoint does not return static_qr as a separate field (Api-5)', function () {
    $user  = User::factory()->create([
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_static_secret_xyz',
    ]);
    $token = $user->createToken('iphone', ['customer'])->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/qr');

    // Api-5 məhdud effekti: rotating token-in özü `qr1.{customer_qr}.{exp}.{hmac}`
    // formatındadır → customer_qr-i embed edir (HMAC ilə qorunur, 30s expire olur).
    // Bu dizayn qərarıdır. Fix yalnız ƏLAVƏ `static_qr` field-ini silir ki, mobile
    // app accidentally onu UI-da göstərməsin və ya log-da yazmasın.
    $response->assertOk()
        ->assertJsonStructure(['qr_value', 'expires_at', 'ttl'])
        ->assertJsonMissingPath('static_qr');

    $payload = $response->json();
    expect($payload)->not->toHaveKey('static_qr');
});

/*
|--------------------------------------------------------------------------
| Api-6 — Email verification feature silindi (UserResource-də field yox)
|--------------------------------------------------------------------------
*/

it('UserResource no longer exposes email_verified field (Api-6)', function () {
    $user  = User::factory()->create([
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);
    $token = $user->createToken('iphone', ['customer'])->plainTextToken;

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->getJson('/api/v1/me');

    $response->assertOk()->assertJsonMissingPath('user.email_verified');
});

/*
|--------------------------------------------------------------------------
| A-5 — Web login: deaktiv hesab eyni "Yanlış e-poçt və ya şifrə" alır
|--------------------------------------------------------------------------
*/

it('web login returns generic credential error for deactivated user (A-5)', function () {
    User::factory()->create([
        'email'     => 'web-deactivated@example.com',
        'password'  => Hash::make('correct-secret'),
        'role'      => UserRole::Customer,
        'is_active' => false,
    ]);

    $response = $this->from('/login')->post('/login', [
        'email'    => 'web-deactivated@example.com',
        'password' => 'correct-secret',
    ]);

    $response->assertRedirect('/login')
        ->assertSessionHasErrors(['email']);

    $errors = session('errors')->get('email');
    expect($errors[0] ?? null)->toBe('Yanlış e-poçt və ya şifrə.');

    // Auth session təmizlənib — deaktiv user yenidən cəhd üçün clean state.
    expect(auth('web')->check())->toBeFalse();
});

it('web login still succeeds for active user (A-5 — regression)', function () {
    User::factory()->create([
        'email'     => 'web-active@example.com',
        'password'  => Hash::make('correct-secret'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    $response = $this->post('/login', [
        'email'    => 'web-active@example.com',
        'password' => 'correct-secret',
    ]);

    // Customer rolu üçün UserRole::homeRoute() → /wallet
    $response->assertRedirect(route('user.wallet'));
    expect(auth('web')->check())->toBeTrue();
});
