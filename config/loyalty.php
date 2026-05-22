<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Earn rates by category (BASIS POINTS — integer)
    |--------------------------------------------------------------------------
    |
    | Bütün dəyərlər basis points-dədir: 10000 = 100.00%, 200 = 2.00%, 500 = 5.00%.
    | Bu config bonus hesablamasının TƌK MƌNBƌYÍDIR (single source of truth).
    | EarnCalculator::calculate() yalnız bu config-i oxuyur — kod daxilində hard-code
    | edilmiş rate və ya multiplier yoxdur.
    |
    | Float yoxdur. round() yoxdur. Float multiplication yoxdur.
    | Hesablama: intdiv(sale_cents * rate_bp * tier_bp, 10000 * 10000)
    |
    */
    'earn_rates_bp' => [
        'grocery'    => 200, // 2.00%
        'restaurant' => 500, // 5.00%
        'fuel'       => 100, // 1.00%
        'pharmacy'   => 300, // 3.00%
        'retail'     => 400, // 4.00%
    ],

    /*
    | Naməlum kateqoriya üçün default rate (basis points).
    */
    'earn_rate_default_bp' => 200, // 2.00%

    /*
    |--------------------------------------------------------------------------
    | Tier multipliers (BASIS POINTS — integer)
    |--------------------------------------------------------------------------
    |
    | 10000 = 1.00x, 12500 = 1.25x, 15000 = 1.50x.
    | Hesablamada multiplicative coefficient kimi tətbiq olunur.
    |
    */
    'tier_multipliers_bp' => [
        'standard'   => 10000, // 1.00x
        'premium'    => 12500, // 1.25x
        'enterprise' => 15000, // 1.50x
    ],

    /*
    |--------------------------------------------------------------------------
    | Bucket expiration
    |--------------------------------------------------------------------------
    |
    | Hər earn-in vaxtı bitir, müəyyən gün sonra. ExpirationJob bu config-i oxuyur.
    |
    */
    'expire_after_days' => 365,

    /*
    |--------------------------------------------------------------------------
    | Redemption rules
    |--------------------------------------------------------------------------
    */
    'redemption' => [
        // Bir satışda maximum nə qədər bonus istifadə oluna bilər (satış məbləğinin faizi, integer %)
        'max_percent_of_sale' => 50,

        // Minimum satış məbləği — bundan aşağı satışda bonus istifadə olunmasın (qəpik)
        'min_sale_cents' => 100, // 1.00 AZN
    ],

];
