<?php

declare(strict_types=1);

use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Ledger immutability — hash chain + DB-level triggers.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->customer = User::factory()->create();
});

it('populates prev_hash and entry_hash on every new ledger entry', function () {
    $first  = $this->ledger->earn($this->customer, $this->merchant, new BonusValue(100), receiptNo: 'r1');
    $second = $this->ledger->earn($this->customer, $this->merchant, new BonusValue(200), receiptNo: 'r2');

    $first->refresh();
    $second->refresh();

    expect($first->prev_hash)->toBeNull();
    expect($first->entry_hash)->not->toBeNull();
    expect(strlen($first->entry_hash))->toBe(64); // sha256 hex

    expect($second->prev_hash)->toBe($first->entry_hash); // chain bağlıdır
    expect($second->entry_hash)->not->toBe($first->entry_hash);
});

it('blocks Eloquent update on a ledger entry (model-level guard)', function () {
    $entry = $this->ledger->earn($this->customer, $this->merchant, new BonusValue(50), receiptNo: 'r_imu_1');

    expect(fn () => $entry->update(['amount' => 99999]))
        ->toThrow(RuntimeException::class, 'immutable');
});

it('blocks Eloquent delete on a ledger entry (model-level guard)', function () {
    $entry = $this->ledger->earn($this->customer, $this->merchant, new BonusValue(50), receiptNo: 'r_imu_2');

    expect(fn () => $entry->delete())
        ->toThrow(RuntimeException::class, 'immutable');
});

it('blocks raw DB UPDATE on ledger_entries via DB-level trigger', function () {
    $entry = $this->ledger->earn($this->customer, $this->merchant, new BonusValue(75), receiptNo: 'r_imu_3');

    expect(fn () => DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => 1]))
        ->toThrow(QueryException::class);

    // Entry plain dəyişməyib.
    $fresh = LedgerEntry::find($entry->id);
    expect($fresh->amount)->toBe(75);
});

it('blocks raw DB DELETE on ledger_entries via DB-level trigger', function () {
    $entry = $this->ledger->earn($this->customer, $this->merchant, new BonusValue(75), receiptNo: 'r_imu_4');

    expect(fn () => DB::table('ledger_entries')->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class);

    expect(LedgerEntry::find($entry->id))->not->toBeNull();
});

it('verifyChain returns valid=true for an untampered chain', function () {
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(100), receiptNo: 'r_chain_1');
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(200), receiptNo: 'r_chain_2');
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(300), receiptNo: 'r_chain_3');

    $result = $this->ledger->verifyChain();

    expect($result['valid'])->toBeTrue();
    expect($result['broken_ids'])->toBe([]);
    expect($result['checked'])->toBe(3);
});

it('verifyChain detects tampering when entry_hash is forcibly altered via trigger-free raw SQL', function () {
    // Bu testdə bilərəkdən trigger-i drop edib raw update edirik ki, verify-in
    // pozulmuş chain-i necə aşkar etdiyini sübut edək.
    $this->ledger->earn($this->customer, $this->merchant, new BonusValue(100), receiptNo: 'r_chain_a');
    $second = $this->ledger->earn($this->customer, $this->merchant, new BonusValue(200), receiptNo: 'r_chain_b');

    DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
    DB::table('ledger_entries')->where('id', $second->id)->update(['amount' => 99999]);

    $result = $this->ledger->verifyChain();
    expect($result['valid'])->toBeFalse();
    expect($result['broken_ids'])->toContain($second->id);
});
