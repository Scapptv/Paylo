<?php

declare(strict_types=1);

use App\Core\Enums\MerchantStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 7.2 — Admin Merchant CRUD.
| Yalnız admin role-undan: list, create, store, show, edit, update.
| `code` və `tin` immutable (update-də prohibited).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role'      => UserRole::Admin,
        'is_active' => true,
    ]);
});

it('shows merchant list to admin', function () {
    Merchant::factory()->count(3)->create(['status' => 'active']);

    $this->actingAs($this->admin)->get('/admin/merchants')
        ->assertOk();
});

it('renders create form to admin', function () {
    $response = $this->actingAs($this->admin)->get('/admin/merchants/create');

    $response->assertOk();
    $props = $response->viewData('page')['props'];
    expect($props['mode'])->toBe('create');
});

it('creates new merchant with valid payload', function () {
    $payload = [
        'code'             => 'm_test01',
        'name'             => 'Test Market',
        'legal_name'       => 'Test Market MMC',
        'tin'              => '1700123456',
        'mcc'              => 5411,
        'category'         => 'grocery',
        'tier'             => 'standard',
        'status'           => 'pending',
        'region'           => 'Bakı',
        'settlement_iban'  => 'AZ21NABZ00000000137010001944',
        'settlement_cycle' => 'T+1',
    ];

    $response = $this->actingAs($this->admin)->postJson('/admin/merchants', $payload);

    $response->assertStatus(302); // redirect

    $merchant = Merchant::where('code', 'm_test01')->firstOrFail();
    expect($merchant->name)->toBe('Test Market');
    expect($merchant->status)->toBe(MerchantStatus::Pending);
});

it('rejects duplicate code on create', function () {
    Merchant::factory()->create(['code' => 'm_dup01']);

    $this->actingAs($this->admin)->postJson('/admin/merchants', [
        'code'             => 'm_dup01',
        'name'             => 'Another',
        'legal_name'       => 'Another MMC',
        'tin'              => '1700999888',
        'mcc'              => 5411,
        'category'         => 'grocery',
        'tier'             => 'standard',
        'status'           => 'pending',
        'region'           => 'Bakı',
        'settlement_iban'  => 'AZ21NABZ00000000137010001944',
        'settlement_cycle' => 'T+1',
    ])->assertStatus(422)->assertJsonValidationErrors(['code']);
});

it('rejects malformed TIN', function () {
    $this->actingAs($this->admin)->postJson('/admin/merchants', [
        'code'             => 'm_bad_tin',
        'name'             => 'X',
        'legal_name'       => 'X MMC',
        'tin'              => '123', // too short
        'mcc'              => 5411,
        'category'         => 'grocery',
        'tier'             => 'standard',
        'status'           => 'pending',
        'region'           => 'Bakı',
        'settlement_iban'  => 'AZ21NABZ00000000137010001944',
        'settlement_cycle' => 'T+1',
    ])->assertStatus(422)->assertJsonValidationErrors(['tin']);
});

it('renders edit form for existing merchant', function () {
    $merchant = Merchant::factory()->create(['status' => 'active']);

    $response = $this->actingAs($this->admin)->get("/admin/merchants/{$merchant->id}/edit");

    $response->assertOk();
    $props = $response->viewData('page')['props'];
    expect($props['mode'])->toBe('edit');
    expect($props['merchant']['id'])->toBe($merchant->id);
});

it('updates merchant fields except code and tin', function () {
    $merchant = Merchant::factory()->create([
        'code'       => 'm_imm01',
        'tin'        => '1700111222',
        'name'       => 'Old Name',
        'status'     => 'pending',
        'category'   => 'grocery',
        'tier'       => 'standard',
    ]);

    $this->actingAs($this->admin)->put("/admin/merchants/{$merchant->id}", [
        'name'             => 'New Name',
        'legal_name'       => $merchant->legal_name,
        'mcc'              => $merchant->mcc,
        'category'         => 'restaurant',
        'tier'             => 'premium',
        'status'           => 'active',
        'region'           => $merchant->region,
        'settlement_iban'  => $merchant->settlement_iban,
        'settlement_cycle' => $merchant->settlement_cycle,
    ])->assertRedirect(route('admin.merchants.show', $merchant));

    $merchant->refresh();
    expect($merchant->name)->toBe('New Name');
    expect($merchant->category)->toBe('restaurant');
    expect($merchant->status)->toBe(MerchantStatus::Active);
    expect($merchant->code)->toBe('m_imm01'); // toxunulmayıb
    expect($merchant->tin)->toBe('1700111222'); // toxunulmayıb
});

it('rejects update attempts to change code or tin (prohibited)', function () {
    $merchant = Merchant::factory()->create([
        'code' => 'm_imm02', 'tin' => '1700333444',
    ]);

    $this->actingAs($this->admin)->putJson("/admin/merchants/{$merchant->id}", [
        'code'             => 'm_changed',
        'tin'              => '9999999999',
        'name'             => $merchant->name,
        'legal_name'       => $merchant->legal_name,
        'mcc'              => $merchant->mcc,
        'category'         => $merchant->category,
        'tier'             => $merchant->tier,
        'status'           => $merchant->status->value,
        'region'           => $merchant->region,
        'settlement_iban'  => $merchant->settlement_iban,
        'settlement_cycle' => $merchant->settlement_cycle,
    ])->assertStatus(422)->assertJsonValidationErrors(['code', 'tin']);
});

it('blocks non-admin roles from CRUD endpoints', function () {
    $cashier = User::factory()->create([
        'role'      => UserRole::Cashier,
        'is_active' => true,
    ]);

    $this->actingAs($cashier)->get('/admin/merchants/create')->assertStatus(403);
    $this->actingAs($cashier)->post('/admin/merchants', [])->assertStatus(403);
});
