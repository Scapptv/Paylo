<?php

declare(strict_types=1);

namespace App\Core\Exceptions;

use App\Core\ValueObjects\BonusValue;
use RuntimeException;

/**
 * Bonus bucket balansı tələb olunan əməliyyatı icra etmək üçün kifayət etmir.
 *
 * Audit C-6: Əvvəl POS/redeem axınında ümumi `RuntimeException` atılırdı —
 * exception handler-də onu validation problemindən fərqləndirmək mümkün
 * deyildi. Domain-specific exception ilə HTTP layer-i bunu 422 + lokalizə
 * mesaja render edə bilər (bootstrap/app.php-də).
 *
 * Sahələr (available / required) müştəri-üzlü mesajda hesabatdan kənar
 * lokalizasiya və UI komponenti tərəfindən istifadə üçün asanlıqla əldə edilə
 * bilsin deyə struktura çıxarılıb.
 */
class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly BonusValue $available,
        public readonly BonusValue $required,
        ?string $context = null,
    ) {
        $message = sprintf(
            'Kifayət qədər bonus yoxdur. Mövcud: %s, tələb olunur: %s%s',
            $available->format(),
            $required->format(),
            $context !== null && $context !== '' ? " ({$context})" : '',
        );

        parent::__construct($message);
    }
}
