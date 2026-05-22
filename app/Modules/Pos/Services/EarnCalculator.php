<?php

declare(strict_types=1);

namespace App\Modules\Pos\Services;

use App\Core\Models\Merchant;
use App\Core\Support\LoyaltyConfigurationException;
use App\Core\ValueObjects\BonusValue;

/**
 * Bonus hesablamasının tək məntiq nöqtəsi.
 *
 * Tək məlumat mənbəyi: config/loyalty.php.
 * Hesablama TAMAMƌN integer-dir — float, round(), float multiplication YOXDUR.
 *
 * Formula:
 *     bonus_cents = intdiv(sale_cents * rate_bp * tier_bp, 10000 * 10000)
 *
 *   sale_cents    — satış məbləği qəpiklə (integer)
 *   rate_bp       — kateqoriya earn faizi basis-point-lə (200 = 2.00%)
 *   tier_bp       — merchant tier multiplier basis-point-lə (12500 = 1.25x)
 *   10000 * 10000 — iki basis-point miqyasının normalizasiyası
 *
 * Nəticə deterministikdir: eyni input → eyni cent, hər platformada, hər PHP versiyasında.
 * Qəpik fərqi qırpma (truncation) ilə baş verir — bonus əsla yuxarı yuvarlanmır.
 */
final class EarnCalculator
{
    private const BP_SCALE = 10000;

    public function calculate(Merchant $merchant, BonusValue $saleAmount): BonusValue
    {
        // ---- Konfiq oxunuşu: fail-fast, səssiz 0-a sürüşmə YOXDUR ----
        $rates = config('loyalty.earn_rates_bp');
        if (! is_array($rates) || $rates === []) {
            throw LoyaltyConfigurationException::missingArray('loyalty.earn_rates_bp');
        }

        $tiers = config('loyalty.tier_multipliers_bp');
        if (! is_array($tiers) || $tiers === []) {
            throw LoyaltyConfigurationException::missingArray('loyalty.tier_multipliers_bp');
        }

        if (! config()->has('loyalty.earn_rate_default_bp')) {
            throw LoyaltyConfigurationException::missingKey('loyalty.earn_rate_default_bp');
        }
        $defaultRate = (int) config('loyalty.earn_rate_default_bp');
        if ($defaultRate < 0) {
            throw LoyaltyConfigurationException::negativeValue('loyalty.earn_rate_default_bp', $defaultRate);
        }

        $rateBp = (int) ($rates[$merchant->category] ?? $defaultRate);
        $tierBp = (int) ($tiers[$merchant->tier] ?? self::BP_SCALE);

        if ($rateBp < 0) {
            throw LoyaltyConfigurationException::negativeValue(
                "loyalty.earn_rates_bp.{$merchant->category}", $rateBp,
            );
        }
        if ($tierBp < 0) {
            throw LoyaltyConfigurationException::negativeValue(
                "loyalty.tier_multipliers_bp.{$merchant->tier}", $tierBp,
            );
        }

        // ---- Hesablama: rate / tier explicitly 0 → mərkəzi disable, valid səssiz halın deyil ----
        if ($rateBp === 0 || $tierBp === 0 || $saleAmount->amount <= 0) {
            return BonusValue::zero();
        }

        // intdiv ilə deterministic, float-suz qırpma.
        $bonusCents = intdiv(
            $saleAmount->amount * $rateBp * $tierBp,
            self::BP_SCALE * self::BP_SCALE,
        );

        return new BonusValue($bonusCents);
    }
}
