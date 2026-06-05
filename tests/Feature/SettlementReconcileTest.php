<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery']);
});

it('passes when bucket counters match ledger totals', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(10.00),
    );
    $this->ledger->redeem(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(3.00),
    );

    $exitCode = Artisan::call('loyalty:settlement-reconcile', ['--for' => 'all']);
    // Artisan::output() qaytarılan stream-i istehlak edir; ikinci çağırış boşdur,
    // ona görə dəyəri bir dəfə tutub bütün assertion-larda istifadə edirik.
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('Settlement reconcile uğurlu')
        ->and($output)->toContain('mismatch yoxdur');
});

it('detects a tampered bucket balance and exits with code 1', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(10.00),
    );

    // Bilərəkdən bucket-i ledger-dən sürüşdür — direct DB update (model
    // immutability rule-larını bypass edir; reconcile bunu tutmalıdır).
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['balance' => 999999]);

    $exitCode = Artisan::call('loyalty:settlement-reconcile', ['--for' => 'all']);
    $output   = Artisan::output();

    expect($exitCode)->toBe(1);
    expect($output)
        ->toContain('MİSMATCH')
        ->and($output)->toContain('balance');
});

it('detects a tampered earned_total counter', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
    );

    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['earned_total' => 99999]);

    $exitCode = Artisan::call('loyalty:settlement-reconcile', ['--for' => 'all']);

    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('earned_total');
});

it('exits cleanly when no buckets exist', function () {
    $exitCode = Artisan::call('loyalty:settlement-reconcile', ['--for' => 'all']);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('yoxlanılacaq bucket yoxdur');
});

it('only inspects buckets within the --merchant filter', function () {
    $merchantB = Merchant::factory()->create(['status' => 'active']);

    // A merchant: healthy
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
    );

    // B merchant: TAMPERED (lakin filtrdən kənardır → reconcile görməməlidir)
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $merchantB,
        amount: BonusValue::fromAzn(5.00),
    );
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $merchantB->id)
        ->update(['balance' => 999]);

    $exitCode = Artisan::call('loyalty:settlement-reconcile', [
        '--for'      => 'all',
        '--merchant' => $this->merchant->id,
    ]);

    expect($exitCode)->toBe(0);
});

it('scopes by activity date when --for=today', function () {
    // Bugünkü earn → reconcile bu bucket-i görəcək
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(2.50),
    );

    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['balance' => 99999]);

    $exitCode = Artisan::call('loyalty:settlement-reconcile', ['--for' => 'today']);

    expect($exitCode)->toBe(1);
});

it('skips buckets that had no activity on the --for date', function () {
    // Bucket yarat, lakin last_activity_at-ı 30 gün əvvələ tıxa
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
    );
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update([
            'last_activity_at' => now()->subDays(30),
            'balance'          => 99999, // tampered, lakin scope-dan kənardır
        ]);

    $exitCode = Artisan::call('loyalty:settlement-reconcile', ['--for' => 'today']);

    expect($exitCode)->toBe(0);
    expect(Artisan::output())->toContain('yoxlanılacaq bucket yoxdur');
});

it('respects --dry-run and still exits 1 on mismatch for alerting', function () {
    $this->ledger->earn(
        customer: $this->customer,
        merchant: $this->merchant,
        amount: BonusValue::fromAzn(5.00),
    );
    Bucket::query()
        ->where('user_id', $this->customer->id)
        ->where('merchant_id', $this->merchant->id)
        ->update(['balance' => 12345]);

    $exitCode = Artisan::call('loyalty:settlement-reconcile', [
        '--for'     => 'all',
        '--dry-run' => true,
    ]);

    // Dry-run exit code yenə də mismatch əsasında qaytarılır — alerting üçün vacibdir
    expect($exitCode)->toBe(1);
    expect(Artisan::output())->toContain('dry-run');
});

it('rejects an invalid --for date format', function () {
    Artisan::call('loyalty:settlement-reconcile', ['--for' => 'not-a-date']);
})->throws(\InvalidArgumentException::class, 'etibarsız tarix formatı');
