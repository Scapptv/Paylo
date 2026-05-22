<?php

declare(strict_types=1);

use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| lockOrCreateBucket race-safety qarantiyalarını yoxlayır.
| Eyni (user_id, merchant_id) cütü üçün heç bir halda iki bucket yaranmamalıdır.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->ledger = app(LedgerService::class);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->customer = User::factory()->create();
});

it('does not create a duplicate bucket when the same (user, merchant) earns twice', function () {
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(100), receiptNo: 'r1');
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(50), receiptNo: 'r2');

    $count = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->count();

    expect($count)->toBe(1);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->first();
    expect($bucket->balance)->toBe(150);
    expect($bucket->earned_total)->toBe(150);
});

it('reuses an existing bucket created out-of-band (simulating a race-condition winner)', function () {
    // Simulyasiya: paralel proses artıq bucket-i yaratmışdır.
    Bucket::create([
        'user_id'        => $this->customer->id,
        'merchant_id'    => $this->merchant->id,
        'balance'        => 0,
        'earned_total'   => 0,
        'redeemed_total' => 0,
        'expired_total'  => 0,
    ]);

    // earn() heç bir exception atmamalıdır və DUPLICATE bucket yaratmamalıdır.
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(75), receiptNo: 'r_race');

    $count = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->count();

    expect($count)->toBe(1);
});

it('keeps buckets isolated across different (user, merchant) pairs', function () {
    $merchantB = Merchant::factory()->create(['status' => 'active']);
    $customerB = User::factory()->create();

    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(10), receiptNo: 'a1');
    $this->ledger->earn($this->customer, $merchantB,    new BonusValue(20), receiptNo: 'b1');
    $this->ledger->earn($customerB,      $this->merchant, new BonusValue(30), receiptNo: 'c1');

    // Hər (user, merchant) cütü üçün AYRI bucket — cəmi 3.
    expect(Bucket::count())->toBe(3);
});
