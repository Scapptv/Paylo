<?php

declare(strict_types=1);

use App\Core\Models\Merchant;
use App\Core\Support\LoyaltyConfigurationException;
use App\Core\ValueObjects\BonusValue;
use App\Modules\Pos\Services\EarnCalculator;

/*
|--------------------------------------------------------------------------
| EarnCalculator — pure integer arithmetic, no DB, no float.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    // config-i izolasiya et — test-lər production config-dən asılı olmasın.
    config()->set('loyalty.earn_rates_bp', [
        'grocery'    => 200, // 2.00%
        'restaurant' => 500, // 5.00%
        'fuel'       => 100, // 1.00%
    ]);
    config()->set('loyalty.earn_rate_default_bp', 200);
    config()->set('loyalty.tier_multipliers_bp', [
        'standard'   => 10000, // 1.00x
        'premium'    => 12500, // 1.25x
        'enterprise' => 15000, // 1.50x
    ]);

    $this->calc = new EarnCalculator();
});

function makeMerchant(string $category, string $tier): Merchant
{
    // Bu unit test DB-yə toxunmur — model attribute-larını manuel set edirik.
    $m = new Merchant();
    $m->category = $category;
    $m->tier     = $tier;

    return $m;
}

it('calculates standard tier — grocery 2% on 50.00 AZN = 1.00 AZN', function () {
    $bonus = $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));

    expect($bonus->amount)->toBe(100); // 5000 * 200 * 10000 / (10000*10000) = 100
});

it('applies premium tier multiplier — restaurant 5% × 1.25 on 80.00 AZN = 5.00 AZN', function () {
    $bonus = $this->calc->calculate(makeMerchant('restaurant', 'premium'), new BonusValue(8000));

    // 8000 * 500 * 12500 / 100_000_000 = 50_000_000_000 / 100_000_000 = 500
    expect($bonus->amount)->toBe(500);
});

it('applies enterprise tier multiplier — fuel 1% × 1.50 on 120.00 AZN = 1.80 AZN', function () {
    $bonus = $this->calc->calculate(makeMerchant('fuel', 'enterprise'), new BonusValue(12000));

    // 12000 * 100 * 15000 / 100_000_000 = 18_000_000_000 / 100_000_000 = 180
    expect($bonus->amount)->toBe(180);
});

it('uses default rate for unknown category', function () {
    $bonus = $this->calc->calculate(makeMerchant('unknown', 'standard'), new BonusValue(10000));

    // default 200 bp: 10000 * 200 * 10000 / 1e8 = 200
    expect($bonus->amount)->toBe(200);
});

it('falls back to 1.00x multiplier for unknown tier', function () {
    $bonus = $this->calc->calculate(makeMerchant('grocery', 'mystery'), new BonusValue(5000));

    expect($bonus->amount)->toBe(100); // tier multiplier defaults to 10000 bp
});

it('truncates (floor) instead of rounding — never overpays', function () {
    // 1234 qəpik * 200 bp * 10000 bp = 2_468_000_000
    // / 100_000_000 = 24.68 → intdiv → 24
    $bonus = $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(1234));

    expect($bonus->amount)->toBe(24);
});

it('is deterministic — same input yields same cents across calls', function () {
    $m   = makeMerchant('restaurant', 'premium');
    $sum = new BonusValue(7777);

    $first  = $this->calc->calculate($m, $sum)->amount;
    $second = $this->calc->calculate($m, $sum)->amount;
    $third  = $this->calc->calculate($m, $sum)->amount;

    expect($first)->toBe($second)->toBe($third);
    // 7777 * 500 * 12500 / 1e8 = 48_606_250_000 / 1e8 = 486 (truncated)
    expect($first)->toBe(486);
});

it('returns zero bonus on zero sale amount', function () {
    $bonus = $this->calc->calculate(makeMerchant('grocery', 'premium'), new BonusValue(0));

    expect($bonus->amount)->toBe(0);
});

it('returns zero bonus when category rate is zero', function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 0]);
    config()->set('loyalty.earn_rate_default_bp', 0);

    $bonus = $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));

    expect($bonus->amount)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| R6 — fail-fast on missing / invalid loyalty configuration
|--------------------------------------------------------------------------
| Səhv konfiq SƏSSİZCƏ sıfır bonus verməməlidir. config:cache stale-ləşsə
| və ya açar düşsə, sistem dərhal LoyaltyConfigurationException atmalıdır.
*/

it('throws when earn_rates_bp config is missing', function () {
    config()->set('loyalty.earn_rates_bp', null);

    $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));
})->throws(LoyaltyConfigurationException::class, 'loyalty.earn_rates_bp');

it('throws when earn_rates_bp config is empty array', function () {
    config()->set('loyalty.earn_rates_bp', []);

    $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));
})->throws(LoyaltyConfigurationException::class, 'loyalty.earn_rates_bp');

it('throws when tier_multipliers_bp config is missing', function () {
    config()->set('loyalty.tier_multipliers_bp', null);

    $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));
})->throws(LoyaltyConfigurationException::class, 'loyalty.tier_multipliers_bp');

it('throws when earn_rate_default_bp key is not set at all', function () {
    $loyalty = config('loyalty');
    unset($loyalty['earn_rate_default_bp']);
    config()->set('loyalty', $loyalty);

    $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));
})->throws(LoyaltyConfigurationException::class, 'loyalty.earn_rate_default_bp');

it('throws when earn_rate_default_bp is negative', function () {
    config()->set('loyalty.earn_rate_default_bp', -100);

    $this->calc->calculate(makeMerchant('unknown', 'standard'), new BonusValue(5000));
})->throws(LoyaltyConfigurationException::class, 'loyalty.earn_rate_default_bp');

it('throws when resolved category rate is negative', function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => -50]);

    $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));
})->throws(LoyaltyConfigurationException::class, 'loyalty.earn_rates_bp.grocery');

it('throws when resolved tier multiplier is negative', function () {
    config()->set('loyalty.tier_multipliers_bp', ['standard' => -10000]);

    $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));
})->throws(LoyaltyConfigurationException::class, 'loyalty.tier_multipliers_bp.standard');

it('explicit zero rate is still a valid intentional disable (no exception)', function () {
    config()->set('loyalty.earn_rates_bp', ['grocery' => 0]);

    $bonus = $this->calc->calculate(makeMerchant('grocery', 'standard'), new BonusValue(5000));
    expect($bonus->amount)->toBe(0);
});
