<?php

declare(strict_types=1);

use App\Core\Models\LoyaltyRule;
use App\Core\Models\Merchant;
use App\Core\Services\LoyaltyRuleResolver;
use App\Core\ValueObjects\BonusValue;
use App\Modules\Pos\Services\EarnCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4.2 — LoyaltyRuleResolver: DB override → config körpüsü.
| Kritik: EarnCalculator DƏYİŞMƏZ qalır; override yalnız config dəyərinin
| MƏNBƏYİNİ genişləndirir, kanonik intdiv hesablaması toxunulmur.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->resolver = app(LoyaltyRuleResolver::class);
});

it('applyOverrides DB dəyərini config-ə yazır', function () {
    LoyaltyRule::create(['key' => 'earn_rates_bp.grocery', 'value' => 500]);

    $this->resolver->applyOverrides();

    expect(config('loyalty.earn_rates_bp.grocery'))->toBe(500);
});

it('KANONİK: EarnCalculator DB-override edilmiş rate-i işlədir', function () {
    // grocery default 200 bp (2%). Override → 500 bp (5%).
    LoyaltyRule::create(['key' => 'earn_rates_bp.grocery', 'value' => 500]);
    $this->resolver->applyOverrides();

    $merchant = Merchant::factory()->create(['category' => 'grocery', 'tier' => 'standard', 'status' => 'active']);
    $bonus    = (new EarnCalculator())->calculate($merchant, new BonusValue(5000)); // 50.00 AZN

    // intdiv(5000 * 500 * 10000, 10000 * 10000) = 250 → 2.50 AZN (50-nin 5%-i)
    expect($bonus->amount)->toBe(250);
});

it('bir kateqoriyanın override-ı digərlərini silmir (cərrahi override)', function () {
    LoyaltyRule::create(['key' => 'earn_rates_bp.grocery', 'value' => 999]);

    $this->resolver->applyOverrides();

    expect(config('loyalty.earn_rates_bp.grocery'))->toBe(999);
    expect(config('loyalty.earn_rates_bp.restaurant'))->toBe(500); // fayl default qorunur
    expect(config('loyalty.earn_rates_bp.fuel'))->toBe(100);
});

it('override yoxdursa config faylı default-u qalır (backward-compatible)', function () {
    $this->resolver->applyOverrides(); // heç bir DB qaydası yox

    expect(config('loyalty.earn_rates_bp.grocery'))->toBe(200);
    expect(config('loyalty.tier_multipliers_bp.premium'))->toBe(12500);
});

it('effective() override-lı qayda üçün mənbəni db, dəyəri yeni göstərir', function () {
    LoyaltyRule::create(['key' => 'earn_rate_default_bp', 'value' => 300]);
    $this->resolver->applyOverrides(); // növbəti request boot-unu simulyasiya et

    $eff = collect($this->resolver->effective());
    $def = $eff->firstWhere('key', 'earn_rate_default_bp');

    expect($def['source'])->toBe('db');
    expect($def['value'])->toBe(300);

    // override olunmamış qayda → default mənbə.
    $groceryRow = $eff->firstWhere('key', 'earn_rates_bp.grocery');
    expect($groceryRow['source'])->toBe('default');
    expect($groceryRow['value'])->toBe(200);
});

it('registry redaktə olunan bütün qayda qruplarını əhatə edir', function () {
    $groups = collect($this->resolver->registry())->pluck('group')->unique()->values()->all();

    expect($groups)->toContain('Earn rates', 'Tier multipliers', 'Redemption', 'Expiration');
});
