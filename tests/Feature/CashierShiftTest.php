<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\Transaction;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Cashier shift endpoint — funksional regresion testləri.
| Sprint 6.4 — Sprint4PolicyTest-də olan Csh-2/3/4 audit yoxlamalarına əlavə
| baza axın testləri: gün filtri, boş shift, authorization, eager loading.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->cashier  = User::factory()->create([
        'role' => UserRole::Cashier, 'merchant_id' => $this->merchant->id, 'is_active' => true,
    ]);
    $this->customer = User::factory()->create(['role' => UserRole::Customer]);
});

it('shows only today transactions in shift (yesterdays are excluded)', function () {
    // Dünənki tx — shift-ə daxil olmamalıdır.
    Transaction::create([
        'receipt_no'      => 'r_yesterday',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 9999, 'earned_amount' => 0, 'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now()->subDay()->setTime(15, 0),
    ]);

    // Bu günkü tx — shift-ə daxil olmalıdır.
    Transaction::create([
        'receipt_no'      => 'r_today',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 500, 'earned_amount' => 10, 'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $stats = $this->actingAs($this->cashier)->get('/cashier/shift')
        ->viewData('page')['props']['shiftStats'];

    expect($stats['transactions'])->toBe(1);
    expect($stats['totalSales'])->toBe(500);
});

it('returns zero stats for empty shift', function () {
    $response = $this->actingAs($this->cashier)->get('/cashier/shift');
    $stats    = $response->viewData('page')['props']['shiftStats'];

    expect($stats['transactions'])->toBe(0);
    expect($stats['totalSales'])->toBe(0);
    expect($stats['totalEarned'])->toBe(0);
    expect($stats['totalRedeemed'])->toBe(0);
    expect($stats['completedCount'])->toBe(0);
    expect($stats['reversedCount'])->toBe(0);
    expect($stats['refundedCount'])->toBe(0);
});

it('blocks non-cashier roles (e.g. customer) from shift endpoint', function () {
    $this->actingAs($this->customer)->get('/cashier/shift')
        ->assertStatus(403);
});

it('blocks unauthenticated requests', function () {
    $this->get('/cashier/shift')->assertRedirect('/login');
});

it('eager-loads customer name in recent transactions (no N+1)', function () {
    Transaction::create([
        'receipt_no'      => 'r_eager',
        'merchant_id'     => $this->merchant->id,
        'cashier_id'      => $this->cashier->id,
        'user_id'         => $this->customer->id,
        'sale_amount'     => 100, 'earned_amount' => 2, 'redeemed_amount' => 0,
        'status'          => 'completed',
        'occurred_at'     => now(),
    ]);

    $recent = $this->actingAs($this->cashier)->get('/cashier/shift')
        ->viewData('page')['props']['recentTransactions'];

    expect($recent)->toHaveCount(1);
    expect($recent[0]['customer']['name'])->toBe($this->customer->name);
});

it('sorts recent transactions by occurred_at DESC', function () {
    // Test deterministik olsun — `now()` günortaya kilidlənir ki, subHours
    // startOfDay-ın altına düşməsin.
    \Illuminate\Support\Carbon::setTestNow(now()->startOfDay()->addHours(12));

    foreach ([50, 30, 10, 40, 20] as $i => $offsetMinutes) {
        Transaction::create([
            'receipt_no'      => "r_sort_{$i}",
            'merchant_id'     => $this->merchant->id,
            'cashier_id'      => $this->cashier->id,
            'user_id'         => $this->customer->id,
            'sale_amount'     => 100 + ($i + 1), 'earned_amount' => 0, 'redeemed_amount' => 0,
            'status'          => 'completed',
            'occurred_at'     => now()->subMinutes($offsetMinutes),
        ]);
    }

    $recent = $this->actingAs($this->cashier)->get('/cashier/shift')
        ->viewData('page')['props']['recentTransactions'];

    // Ən yeni (10 dəq əvvəl, i=2 → sale_amount=103) ən üstdə.
    // Sıra: i=2 (10m) → i=4 (20m) → i=1 (30m) → i=3 (40m) → i=0 (50m)
    expect(count($recent))->toBe(5);
    expect($recent[0]['sale_amount'])->toBe(103);
    expect($recent[4]['sale_amount'])->toBe(101);
});
