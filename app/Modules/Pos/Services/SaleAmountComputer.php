<?php

declare(strict_types=1);

namespace App\Modules\Pos\Services;

use App\Core\Models\Merchant;
use App\Core\Support\LoyaltyConfigurationException;
use App\Core\ValueObjects\BonusValue;

/**
 * Preview və complete üçün TƏK hesablama yolu. Həm Inertia POS controller-i
 * (Modules\Pos\Http\Controllers\SaleController), həm də M2M POS API
 * (Modules\Api\Http\Controllers\V1\Pos\PosSaleController) bu xidməti istifadə
 * edir — formul iki səth arasında drift edə bilməz.
 *
 * Redemption biznes qaydaları (audit Cfg-1) — config/loyalty.php · redemption:
 *  - `min_sale_cents`: bundan kiçik satışda bonus istifadəsi qadağandır.
 *  - `max_percent_of_sale`: satışın yalnız bu faizi bonusla ödənə bilər.
 *
 * Final redeem cap:  min(bucket_balance, sale_amount, sale_amount × max% ÷ 100)
 */
final class SaleAmountComputer
{
    public function __construct(private readonly EarnCalculator $earn)
    {
    }

    /**
     * @return array{sale: BonusValue, earn: BonusValue, redeem: BonusValue}
     */
    public function compute(
        int $saleAmountCents,
        bool $useBonus,
        int $redeemCentsRaw,
        Merchant $merchant,
        int $bucketBalance,
    ): array {
        $sale = new BonusValue($saleAmountCents);
        $earn = $this->earn->calculate($merchant, $sale);

        if (! $useBonus) {
            return ['sale' => $sale, 'earn' => $earn, 'redeem' => BonusValue::zero()];
        }

        $minSaleCents = $this->redemptionMinSaleCents();
        if ($saleAmountCents < $minSaleCents) {
            return ['sale' => $sale, 'earn' => $earn, 'redeem' => BonusValue::zero()];
        }

        // intdiv ilə deterministik, yuxarı yuvarlanma yox.
        $percentCap = intdiv($saleAmountCents * $this->redemptionMaxPercent(), 100);

        $cap    = min($bucketBalance, $sale->amount, $percentCap);
        $redeem = new BonusValue(max(0, min($redeemCentsRaw, $cap)));

        return ['sale' => $sale, 'earn' => $earn, 'redeem' => $redeem];
    }

    private function redemptionMinSaleCents(): int
    {
        if (! config()->has('loyalty.redemption.min_sale_cents')) {
            throw LoyaltyConfigurationException::missingKey('loyalty.redemption.min_sale_cents');
        }

        $value = (int) config('loyalty.redemption.min_sale_cents');
        if ($value < 0) {
            throw LoyaltyConfigurationException::negativeValue('loyalty.redemption.min_sale_cents', $value);
        }

        return $value;
    }

    private function redemptionMaxPercent(): int
    {
        if (! config()->has('loyalty.redemption.max_percent_of_sale')) {
            throw LoyaltyConfigurationException::missingKey('loyalty.redemption.max_percent_of_sale');
        }

        $value = (int) config('loyalty.redemption.max_percent_of_sale');
        if ($value < 0) {
            throw LoyaltyConfigurationException::negativeValue('loyalty.redemption.max_percent_of_sale', $value);
        }
        if ($value > 100) {
            throw new LoyaltyConfigurationException(
                "Loyalty configuration key [loyalty.redemption.max_percent_of_sale] 0..100 aralığında olmalıdır, alınan: {$value}."
            );
        }

        return $value;
    }
}
