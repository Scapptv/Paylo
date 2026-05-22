<?php

declare(strict_types=1);

namespace App\Core\Support;

use RuntimeException;

/**
 * Loyalty modul konfiqurasiyasının yanlış və ya natamam olmasını bildirir.
 *
 * Bu exception fail-fast prinsipi üçündür: səhv konfiq SƏSSİZCƏ sıfır
 * bonus verməməlidir. Bunun yerinə proqram dayanıb səhvi açıq elan edir.
 *
 * Tipik səbəblər:
 *  - config/loyalty.php cache-dən düşüb (config:cache stale)
 *  - earn_rates_bp / tier_multipliers_bp boş və ya array deyil
 *  - earn_rate_default_bp təyin olunmayıb (səssiz 0 təhlükəlidir)
 *  - Resolve olunan rate / tier mənfi qiymət alıb
 */
final class LoyaltyConfigurationException extends RuntimeException
{
    public static function missingArray(string $key): self
    {
        return new self(
            "Loyalty configuration key [{$key}] boşdur və ya array deyil. "
            . 'config/loyalty.php düzgün yüklənibmi? (php artisan config:clear)'
        );
    }

    public static function missingKey(string $key): self
    {
        return new self(
            "Loyalty configuration key [{$key}] təyin olunmayıb. "
            . 'Defaultu səssiz 0-a sürüşməsin deyə bu dəyər mütləq explicit olmalıdır.'
        );
    }

    public static function negativeValue(string $key, int $value): self
    {
        return new self(
            "Loyalty configuration key [{$key}] mənfi dəyər alıb ({$value}). "
            . 'Basis-point dəyərləri ≥ 0 olmalıdır.'
        );
    }
}
