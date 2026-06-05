<?php

declare(strict_types=1);

use App\Core\Enums\MerchantStatus;
use App\Core\Enums\TransactionStatus;

/*
|--------------------------------------------------------------------------
| Audit C-8 / C-9 — domain status enum-larının davranışı.
|--------------------------------------------------------------------------
*/

it('MerchantStatus exposes all four cases', function () {
    expect(MerchantStatus::values())->toBe(['active', 'pending', 'paused', 'revoked']);
});

it('only MerchantStatus::Active can transact', function () {
    expect(MerchantStatus::Active->canTransact())->toBeTrue();
    expect(MerchantStatus::Pending->canTransact())->toBeFalse();
    expect(MerchantStatus::Paused->canTransact())->toBeFalse();
    expect(MerchantStatus::Revoked->canTransact())->toBeFalse();
});

it('TransactionStatus exposes completed/refunded/reversed', function () {
    expect(TransactionStatus::values())->toBe(['completed', 'refunded', 'reversed']);
});

it('only TransactionStatus::Completed is non-terminal', function () {
    expect(TransactionStatus::Completed->isTerminal())->toBeFalse();
    expect(TransactionStatus::Refunded->isTerminal())->toBeTrue();
    expect(TransactionStatus::Reversed->isTerminal())->toBeTrue();
});
