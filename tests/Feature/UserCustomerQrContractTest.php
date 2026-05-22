<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| R7 — customer_qr non-null contract for active customers
|--------------------------------------------------------------------------
| Domain invariantı: role = Customer + is_active = true → customer_qr MƏCBURİ
| unique, non-empty. Boot listener bunu silent yolla təmin edir ki, factory,
| manual create, admin paneli və ya migration backfill heç vaxt null
| Customer yarada bilməsin.
*/

it('auto-fills customer_qr when an active customer is saved without one', function () {
    $user = User::create([
        'name'      => 'No QR Customer',
        'email'     => 'no-qr@example.com',
        'password'  => 'secret-pass',
        'role'      => UserRole::Customer,
        'is_active' => true,
        // customer_qr explicit ötürülmür
    ]);

    expect($user->customer_qr)
        ->toBeString()
        ->toStartWith('qr_')
        ->toHaveLength(15);
});

it('preserves explicitly provided customer_qr (does not overwrite)', function () {
    $user = User::create([
        'name'        => 'Has QR',
        'email'       => 'has-qr@example.com',
        'password'    => 'secret-pass',
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_explicit12345',
    ]);

    expect($user->customer_qr)->toBe('qr_explicit12345');
});

it('does NOT auto-fill customer_qr for non-customer roles', function () {
    $admin = User::create([
        'name'      => 'Root',
        'email'     => 'root@example.com',
        'password'  => 'secret-pass',
        'role'      => UserRole::Admin,
        'is_active' => true,
    ]);

    expect($admin->customer_qr)->toBeNull();
});

it('does NOT auto-fill customer_qr for inactive (anonymised/soft-deleted) customers', function () {
    // ProfileController::delete() anonimləşdirmə axını: customer + is_active=false
    // + customer_qr = null. Boot listener bu state-i pozmamalıdır.
    $deleted = User::create([
        'name'        => 'Silinmiş istifadəçi #999',
        'email'       => 'deleted+999@paylo.deleted',
        'password'    => 'secret-pass',
        'role'        => UserRole::Customer,
        'is_active'   => false,
        'customer_qr' => null,
    ]);

    expect($deleted->customer_qr)->toBeNull();
});

it('generates unique customer_qr — collisions are retried', function () {
    // 10 customer create et, hamısı unique olmalıdır
    $qrs = collect(range(1, 10))->map(function (int $i) {
        return User::create([
            'name'      => "C{$i}",
            'email'     => "c{$i}@example.com",
            'password'  => 'secret-pass',
            'role'      => UserRole::Customer,
            'is_active' => true,
        ])->customer_qr;
    });

    expect($qrs->unique()->count())->toBe(10);
    $qrs->each(fn (string $qr) => expect($qr)->toStartWith('qr_')->toHaveLength(15));
});

it('generateUniqueCustomerQr static helper avoids existing values', function () {
    User::create([
        'name'        => 'Holder',
        'email'       => 'holder@example.com',
        'password'    => 'secret-pass',
        'role'        => UserRole::Customer,
        'is_active'   => true,
        'customer_qr' => 'qr_takentaken1', // 15 chars
    ]);

    $fresh = User::generateUniqueCustomerQr();

    expect($fresh)->not->toBe('qr_takentaken1');
    expect(User::where('customer_qr', $fresh)->exists())->toBeFalse();
});

it('auto-fills customer_qr when updating a customer that has none (defensive backfill)', function () {
    // Backfill ssenarisi: köhnə DB-də NULL customer_qr ilə Customer mövcuddur
    // (məs. R7-dən əvvəlki seed). Save zamanı avtomatik dolur.
    $user = User::factory()->create([
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);
    // Direct DB update — model event-lərini bypass edir, NULL state simulate edir.
    \DB::table('users')->where('id', $user->id)->update(['customer_qr' => null]);

    $stale = User::find($user->id);
    expect($stale->customer_qr)->toBeNull();

    $stale->name = 'Updated';
    $stale->save();

    expect($stale->fresh()->customer_qr)->toBeString()->toStartWith('qr_');
});
