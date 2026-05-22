<?php

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery']);
});

it('credits a bucket on earn', function () {
    $entry = $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
        receiptNo: 'r_test_1',
    );

    expect($entry->type)->toBe(LedgerEntryType::Earn);
    expect($entry->amount)->toBe(500);

    $bucket = $this->customer->buckets()->where('merchant_id', $this->merchant->id)->first();
    expect($bucket->balance)->toBe(500);
});

it('debits a bucket on redeem', function () {
    // əvvəlcə earn
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(10.00),
    );

    // sonra redeem
    $this->ledger->redeem(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(3.00),
    );

    $bucket = $this->customer->buckets()->where('merchant_id', $this->merchant->id)->first();
    expect($bucket->balance)->toBe(700);
});

it('prevents overdraft', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(2.00),
    );

    $this->ledger->redeem(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
    );
})->throws(\RuntimeException::class, 'Kifayət qədər bonus yoxdur');

it('cannot earn on inactive merchant', function () {
    $this->merchant->update(['status' => 'paused']);

    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
    );
})->throws(\RuntimeException::class, 'Merchant aktiv deyil');

it('ledger entries are immutable', function () {
    $entry = $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
    );

    expect(fn () => $entry->update(['amount' => 9999]))
        ->toThrow(\RuntimeException::class, 'immutable');

    expect(fn () => $entry->delete())
        ->toThrow(\RuntimeException::class, 'immutable');
});

it('buckets are merchant-scoped — earn at A does not affect bucket at B', function () {
    $merchantB = Merchant::factory()->create(['status' => 'active']);

    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(10.00),
    );

    // Merchant B-də redeem cəhdi — bucket yoxdur
    expect(fn () => $this->ledger->redeem(
        customer: $this->customer,
        merchant: $merchantB,
        amount: BonusValue::fromAzn(5.00),
    ))->toThrow(\RuntimeException::class, 'bucket yoxdur');
});
