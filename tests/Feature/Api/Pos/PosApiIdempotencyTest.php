<?php

declare(strict_types=1);

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\UserRole;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

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

    Sanctum::actingAs($this->posUser, abilities: ['pos:write']);
});

it('replays a cached response when Idempotency-Key is reused with the same body', function () {
    $key = 'idem-' . str_repeat('a', 16);
    $payload = [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'idem-r-1',
        'use_bonus'         => false,
    ];

    $first  = $this->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/pos/sale', $payload);
    $second = $this->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/pos/sale', $payload);

    $first->assertOk();
    $second->assertOk();
    $second->assertHeader('Idempotent-Replay', 'true');

    // Tək transaction, tək earn ledger entry — sale flow yalnız bir dəfə icra olundu.
    expect(Transaction::where('receipt_no', 'idem-r-1')->count())->toBe(1);
    expect(LedgerEntry::where('type', LedgerEntryType::Earn)->count())->toBe(1);

    // Cavab gövdəsi eyni olmalıdır.
    expect($second->json('transaction_id'))->toBe($first->json('transaction_id'));
});

it('rejects 422 when the same Idempotency-Key is reused with a different body', function () {
    $key = 'idem-' . str_repeat('b', 16);

    $this->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/pos/sale', [
            'customer_id'       => $this->customer->id,
            'sale_amount_cents' => 5000,
            'receipt_no'        => 'idem-r-2',
            'use_bonus'         => false,
        ])->assertOk();

    $conflict = $this->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/pos/sale', [
            'customer_id'       => $this->customer->id,
            'sale_amount_cents' => 9999, // different
            'receipt_no'        => 'idem-r-2-diff',
            'use_bonus'         => false,
        ]);

    $conflict->assertStatus(422)
        ->assertJsonValidationErrors(['Idempotency-Key']);
});

it('rejects 422 when Idempotency-Key has invalid format', function () {
    $payload = [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'idem-fmt',
        'use_bonus'         => false,
    ];

    // < 8 simvol → 422
    $this->withHeaders(['Idempotency-Key' => 'tiny'])
        ->postJson('/api/v1/pos/sale', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['Idempotency-Key']);

    // > 128 simvol → 422
    $this->withHeaders(['Idempotency-Key' => str_repeat('x', 129)])
        ->postJson('/api/v1/pos/sale', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['Idempotency-Key']);

    // Boşluq simvolu icazə verilmir → 422
    $this->withHeaders(['Idempotency-Key' => 'has spaces in it'])
        ->postJson('/api/v1/pos/sale', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['Idempotency-Key']);
});

it('does NOT cache non-2xx responses (failed sale can be retried)', function () {
    $key = 'idem-' . str_repeat('c', 16);

    // İlk sorğu validation error qaytaracaq (customer_id yoxdur)
    $this->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/pos/sale', [
            'sale_amount_cents' => 5000,
            'receipt_no'        => 'idem-r-fail',
            'use_bonus'         => false,
        ])->assertStatus(422);

    // Eyni açar ilə düzgün payload göndərək — replay yox, yeni iş kimi qəbul olmalı.
    $this->withHeaders(['Idempotency-Key' => $key])
        ->postJson('/api/v1/pos/sale', [
            'customer_id'       => $this->customer->id,
            'sale_amount_cents' => 5000,
            'receipt_no'        => 'idem-r-fail',
            'use_bonus'         => false,
        ])->assertOk()->assertJson(['idempotent' => false]);
});

it('does not affect requests sent without an Idempotency-Key header', function () {
    // Header opsionaldır — domain-level idempotency hələ də qoruyur.
    $payload = [
        'customer_id'       => $this->customer->id,
        'sale_amount_cents' => 5000,
        'receipt_no'        => 'idem-r-bare',
        'use_bonus'         => false,
    ];

    $this->postJson('/api/v1/pos/sale', $payload)
        ->assertOk()
        ->assertJson(['idempotent' => false]);

    $this->postJson('/api/v1/pos/sale', $payload)
        ->assertOk()
        ->assertJson(['idempotent' => true]); // domain-level catch
});
