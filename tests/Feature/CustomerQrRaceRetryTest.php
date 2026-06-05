<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Audit C-5 — generateUniqueCustomerQr race-də User::save retry.
| Pre-check + INSERT arasında race-i simulyasiya etmək üçün ilk save-dən
| qabaq əl ilə eyni `customer_qr`-i başqa user-ə yazırıq.
|--------------------------------------------------------------------------
*/

it('retries customer_qr generation when unique constraint hits on save', function () {
    // İlk user — onun QR-ini bilərək təxmin etməyəcəyik, sadəcə yaranan dəyər saxlanır.
    $first = User::create([
        'name'      => 'Birinci',
        'email'     => 'first-c5@example.com',
        'password'  => bcrypt('secret-pass-12'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);
    expect($first->customer_qr)->not->toBeNull();

    // İkinci user — saving event yenidən təsadüfi QR generasiya edəcək. Race-i
    // birbaşa burada simulyasiya etmək çətindir (DB-də artıq mövcud olan QR-i
    // əl ilə təyin etsək, saving event onu üstələyə bilir).
    // Real race ssenarisini test etmək üçün — generateUniqueCustomerQr-ə müraciət
    // edən paralel sorğu — `customer_qr`-i əl ilə həm `first`-in QR-i ilə təyin
    // edək və saving event-i bypass edək; save() retry mexanizmi `is_active`
    // customer və mövcud `customer_qr` halında işə düşməlidir.
    $second = new User([
        'name'      => 'İkinci',
        'email'     => 'second-c5@example.com',
        'password'  => bcrypt('secret-pass-12'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);
    $second->customer_qr = $first->customer_qr; // bilərəkdən kolliziya

    $second->save();

    // Retry mexanizmi yeni QR generasiya etmiş və save uğurlu olmalıdır.
    expect($second->id)->not->toBeNull();
    expect($second->customer_qr)->not->toBe($first->customer_qr);
});

it('does not swallow unrelated unique constraint violations (e.g. duplicate email)', function () {
    User::create([
        'name'      => 'İlk',
        'email'     => 'duplicate@example.com',
        'password'  => bcrypt('secret-pass-12'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]);

    // Eyni email — UniqueConstraintViolationException atılmalıdır, retry yox.
    expect(fn () => User::create([
        'name'      => 'İkinci',
        'email'     => 'duplicate@example.com',
        'password'  => bcrypt('secret-pass-12'),
        'role'      => UserRole::Customer,
        'is_active' => true,
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});
