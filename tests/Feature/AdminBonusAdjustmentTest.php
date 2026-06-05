<?php

declare(strict_types=1);

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Audit 2026-06-04 CANON-4 — admin manual bonus düzəlişi (CREDIT-only).
| POST /admin/bonus-adjustments. adjust() artıq var idi; bu endpoint onu
| admin üçün təhlükəsiz şəkildə açır (bərpa yolu / goodwill kredit).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->admin    = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->customer = User::factory()->create(['role' => UserRole::Customer, 'is_active' => true]);
});

it('admin credits a customer bucket and records an Adjustment entry (CANON-4)', function () {
    Bucket::create([
        'user_id' => $this->customer->id, 'merchant_id' => $this->merchant->id,
        'balance' => 500, 'earned_total' => 500, 'redeemed_total' => 0, 'expired_total' => 0,
    ]);

    $response = $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'customer_id'  => $this->customer->id,
        'merchant_id'  => $this->merchant->id,
        'amount_cents' => 250,
        'reason'       => 'goodwill — şikayət həlli',
    ]);

    $response->assertStatus(201)->assertJson(['status' => 'ok']);
    expect($response->json('entry.type'))->toBe('adjustment');
    expect($response->json('entry.amount'))->toBe(250);
    expect($response->json('bucket.balance'))->toBe(750); // 500 + 250

    // earned_total dəyişmir — adjustment earn deyil.
    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->first();
    expect((int) $bucket->earned_total)->toBe(500);

    // Immutable ledger-də Adjustment entry + audit meta.
    $entry = LedgerEntry::where('type', LedgerEntryType::Adjustment)->latest('id')->firstOrFail();
    expect((int) $entry->amount)->toBe(250);
    expect($entry->meta['reason'])->toBe('goodwill — şikayət həlli');
    expect((int) $entry->meta['admin_id'])->toBe($this->admin->id);

    // Hash chain bütöv qalır.
    expect(app(LedgerService::class)->verifyChain()['valid'])->toBeTrue();
});

it('creates a bucket when the customer has none yet', function () {
    $response = $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'customer_id'  => $this->customer->id,
        'merchant_id'  => $this->merchant->id,
        'amount_cents' => 1000,
        'reason'       => 'ilkin mükafat',
    ]);

    $response->assertStatus(201);
    expect($response->json('bucket.balance'))->toBe(1000);
    expect((int) Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->value('balance'))->toBe(1000);
});

it('requires a reason of at least 3 chars', function () {
    $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'customer_id' => $this->customer->id, 'merchant_id' => $this->merchant->id,
        'amount_cents' => 100, 'reason' => 'ab',
    ])->assertStatus(422)->assertJsonValidationErrors(['reason']);
});

it('rejects zero amount (credit-only, must be positive)', function () {
    $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'customer_id' => $this->customer->id, 'merchant_id' => $this->merchant->id,
        'amount_cents' => 0, 'reason' => 'test reason',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount_cents']);
});

it('rejects the legacy float amount field', function () {
    $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'customer_id' => $this->customer->id, 'merchant_id' => $this->merchant->id,
        'amount' => 2.50, 'amount_cents' => 250, 'reason' => 'test reason',
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

it('rejects a non-customer target', function () {
    $cashier = User::factory()->create([
        'role' => UserRole::Cashier, 'merchant_id' => $this->merchant->id,
    ]);

    $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'customer_id' => $cashier->id, 'merchant_id' => $this->merchant->id,
        'amount_cents' => 100, 'reason' => 'test reason',
    ])->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
});

it('rejects a deactivated customer (no credit to dead accounts)', function () {
    $inactive = User::factory()->create(['role' => UserRole::Customer, 'is_active' => false]);

    $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'customer_id' => $inactive->id, 'merchant_id' => $this->merchant->id,
        'amount_cents' => 100, 'reason' => 'test reason',
    ])->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
});

it('blocks non-admin roles (e.g. merchant_owner)', function () {
    $owner = User::factory()->create([
        'role' => UserRole::MerchantOwner, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);

    $this->actingAs($owner)->postJson('/admin/bonus-adjustments', [
        'customer_id' => $this->customer->id, 'merchant_id' => $this->merchant->id,
        'amount_cents' => 100, 'reason' => 'test reason',
    ])->assertStatus(403);

    // Heç bir ledger entry yaranmamalıdır.
    expect(LedgerEntry::where('type', LedgerEntryType::Adjustment)->count())->toBe(0);
});

it('blocks unauthenticated requests', function () {
    $this->postJson('/admin/bonus-adjustments', [
        'customer_id' => $this->customer->id, 'merchant_id' => $this->merchant->id,
        'amount_cents' => 100, 'reason' => 'test reason',
    ])->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Roadmap Phase 1.1 — admin UI (Inertia) səthi: create forması + email ilə kredit.
| API (customer_id/JSON) kontraktı yuxarıdakı testlərlə qorunur.
|--------------------------------------------------------------------------
*/

it('Phase 1.1: renders the manual adjustment form to admin with merchants', function () {
    Merchant::factory()->create(['status' => 'active']); // dropdown üçün əlavə merchant

    $this->actingAs($this->admin)
        ->get('/admin/bonus-adjustments')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/BonusAdjustment')
            ->has('merchants')
        );
});

it('Phase 1.1: credits via email from the web form and redirects (not JSON)', function () {
    $response = $this->actingAs($this->admin)->post('/admin/bonus-adjustments', [
        'email'        => $this->customer->email,
        'merchant_id'  => $this->merchant->id,
        'amount_cents' => 300,
        'reason'       => 'goodwill via UI',
    ]);

    $response->assertRedirect(); // Inertia/web → redirect, JSON deyil

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->first();
    expect((int) $bucket->balance)->toBe(300);
    expect(LedgerEntry::where('type', LedgerEntryType::Adjustment)
        ->where('user_id', $this->customer->id)->count())->toBe(1);
});

it('Phase 1.1: rejects an unknown / non-customer email', function () {
    $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'email' => 'yoxdur@nowhere.az', 'merchant_id' => $this->merchant->id,
        'amount_cents' => 100, 'reason' => 'bad email',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

it('Phase 1.1: rejects when neither customer_id nor email is provided', function () {
    $this->actingAs($this->admin)->postJson('/admin/bonus-adjustments', [
        'merchant_id' => $this->merchant->id, 'amount_cents' => 100, 'reason' => 'no target',
    ])->assertStatus(422)->assertJsonValidationErrors(['customer_id', 'email']);
});
