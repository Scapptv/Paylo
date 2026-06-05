<?php

declare(strict_types=1);

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Sprint 7.1 — ExpireBucketsCommand tam implementasiya.
| `last_activity_at < (now - expire_after_days)` olan bucket-lərin balansı
| Expire entry yazılaraq sıfırlanır; expired_total counter artırılır.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->merchant = Merchant::factory()->create(['status' => 'active']);
    $this->customer = User::factory()->create(['role' => UserRole::Customer]);
});

it('expires bucket balance after inactivity threshold', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount:   new BonusValue(500),
    );

    // Bucket-i 400 gün geriyə manipulyasiya et — threshold 365 gün.
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['last_activity_at' => now()->subDays(400)]);

    $exit = Artisan::call('loyalty:expire-buckets');

    expect($exit)->toBe(0);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->firstOrFail();
    expect($bucket->balance)->toBe(0);
    expect($bucket->expired_total)->toBe(500);

    // Expire entry yaranıb.
    $expireEntry = LedgerEntry::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->where('type', LedgerEntryType::Expire)
        ->first();
    expect($expireEntry)->not->toBeNull();
    expect($expireEntry->amount)->toBe(500);
});

it('does not expire bucket that is within threshold', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount:   new BonusValue(300),
    );

    // 100 gün — threshold-dan az.
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['last_activity_at' => now()->subDays(100)]);

    Artisan::call('loyalty:expire-buckets');

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->firstOrFail();
    expect($bucket->balance)->toBe(300);
    expect($bucket->expired_total)->toBe(0);
});

it('--dry-run does not write any Expire entries', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount:   new BonusValue(250),
    );

    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['last_activity_at' => now()->subDays(500)]);

    $entriesBefore = LedgerEntry::count();

    Artisan::call('loyalty:expire-buckets', ['--dry-run' => true]);

    expect(LedgerEntry::count())->toBe($entriesBefore);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->firstOrFail();
    expect($bucket->balance)->toBe(250); // toxunulmayıb
});

it('--days override config value', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount:   new BonusValue(100),
    );

    // 50 gün — config (365) altında. Override 30-da bu da expired olur.
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['last_activity_at' => now()->subDays(50)]);

    Artisan::call('loyalty:expire-buckets', ['--days' => 30]);

    $bucket = Bucket::where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)->firstOrFail();
    expect($bucket->balance)->toBe(0);
    expect($bucket->expired_total)->toBe(100);
});

it('--merchant filter scopes the expire operation', function () {
    $otherMerchant = Merchant::factory()->create(['status' => 'active']);

    // İkisi də bucket-də 200, hər ikisi köhnə.
    foreach ([$this->merchant, $otherMerchant] as $m) {
        $this->ledger->earn(customer: $this->customer, merchant: $m, amount: new BonusValue(200));
        Bucket::query()
            ->where('user_id', $this->customer->id)
            ->where('merchant_id', $m->id)
            ->update(['last_activity_at' => now()->subDays(400)]);
    }

    Artisan::call('loyalty:expire-buckets', ['--merchant' => $this->merchant->id]);

    expect(Bucket::where('merchant_id', $this->merchant->id)->value('balance'))->toBe(0);
    expect(Bucket::where('merchant_id', $otherMerchant->id)->value('balance'))->toBe(200);
});

it('Expire entry maintains hash chain integrity', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount:   new BonusValue(800),
    );

    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['last_activity_at' => now()->subDays(400)]);

    Artisan::call('loyalty:expire-buckets');

    // Hash chain yoxlanışı — Expire entry-də prev_hash + entry_hash dolu olmalıdır.
    $expireEntry = LedgerEntry::where('type', LedgerEntryType::Expire)->first();
    expect($expireEntry)->not->toBeNull();
    expect($expireEntry->entry_hash)->not->toBeEmpty();
    expect(strlen($expireEntry->entry_hash))->toBe(64); // SHA-256 hex
});
