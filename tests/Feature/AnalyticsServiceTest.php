<?php

declare(strict_types=1);

use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\AnalyticsService;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4.1 — AnalyticsService KANONİK düzgünlük testləri.
| Düstur: liability = Σcredits − Σdebits (credit=earn/adjustment/transfer,
| debit=redeem/refund/reversal/expire), integer qəpik, ledger = həqiqət mənbəyi.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->ledger   = app(LedgerService::class);
    $this->analytics = app(AnalyticsService::class);
    $this->customer = User::factory()->create(['role' => UserRole::Customer]);
    $this->merchant = Merchant::factory()->create(['status' => 'active', 'category' => 'grocery']);
});

it('KANONİK: liabilityTrend son dəyəri Σ bucket.balance-a bərabərdir', function () {
    $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(10.00));
    $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(5.00));
    $this->ledger->redeem(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(3.00));

    $data  = $this->analytics->overview(30);
    $trend = $data['liabilityTrend'];
    $last  = end($trend);

    // Ledger-dən rekonstruksiya (Σcredits−Σdebits) == denormalized bucket balansı.
    expect($last['liability'])->toBe((int) Bucket::sum('balance'));
    expect($last['liability'])->toBe(1200); // 1000 + 500 − 300
});

it('KANONİK: kpis earnedAll/redeemedAll/liability ledger ilə uyğundur', function () {
    $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(10.00));
    $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(5.00));
    $this->ledger->redeem(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(3.00));

    $k = $this->analytics->overview(30)['kpis'];

    expect($k['earnedAll'])->toBe(1500);
    expect($k['redeemedAll'])->toBe(300);
    expect($k['liability'])->toBe(1200);
});

it('KANONİK: refund debit-dir və liability-ni azaldır', function () {
    $earn = $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(10.00));
    $this->ledger->refund($earn); // tam refund — earn-i geri qaytarır (−1000)

    $data = $this->analytics->overview(30);
    $last = end($data['liabilityTrend']);

    // Σcredits − Σdebits = 1000 − 1000 = 0
    expect((int) Bucket::sum('balance'))->toBe(0);
    expect($last['liability'])->toBe(0);

    $refund = collect($data['typeBreakdown'])->firstWhere('type', 'refund');
    expect($refund['flow'])->toBe('debit');
    expect($refund['total'])->toBe(1000);
});

it('KANONİK: typeBreakdown credit/debit-i düzgün təsnif edir', function () {
    $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(7.00));
    $this->ledger->redeem(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(2.00));

    $bd = collect($this->analytics->overview(30)['typeBreakdown']);

    expect($bd->firstWhere('type', 'earn')['flow'])->toBe('credit');
    expect($bd->firstWhere('type', 'earn')['total'])->toBe(700);
    expect($bd->firstWhere('type', 'redeem')['flow'])->toBe('debit');
    expect($bd->firstWhere('type', 'redeem')['total'])->toBe(200);
    // Bütün 7 tip mövcuddur (sıfır olsa da).
    expect($bd)->toHaveCount(7);
});

it('dailyFlow sıfır-dolu pəncərədir və cəmlər uyğundur', function () {
    $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(10.00));
    $this->ledger->redeem(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(3.00));

    $flow = collect($this->analytics->overview(30)['dailyFlow']);

    expect($flow)->toHaveCount(30); // 30 günlük sıfır-dolu seriya
    expect($flow->sum('earned'))->toBe(1000);
    expect($flow->sum('redeemed'))->toBe(300);
});

it('topMerchants liability üzrə sıralanır', function () {
    $mBig = Merchant::factory()->create(['status' => 'active']);
    $this->ledger->earn(customer: $this->customer, merchant: $mBig, amount: BonusValue::fromAzn(50.00));
    $this->ledger->earn(customer: $this->customer, merchant: $this->merchant, amount: BonusValue::fromAzn(5.00));

    $top = $this->analytics->overview(30)['topMerchants'];

    expect($top[0]['id'])->toBe($mBig->id);       // ən böyük liability birinci
    expect($top[0]['liability'])->toBe(5000);
    expect($top[0]['earned'])->toBe(5000);
});

it('boş ledger-də metriklər sıfırdır, qrafiklər boş deyil', function () {
    $data = $this->analytics->overview(7);

    expect($data['kpis']['liability'])->toBe(0);
    expect($data['kpis']['earnedAll'])->toBe(0);
    expect($data['dailyFlow'])->toHaveCount(7);
    expect($data['liabilityTrend'])->toHaveCount(7);
    $last = end($data['liabilityTrend']);
    expect($last['liability'])->toBe(0);
});
