<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Tranzaksiyanın həyat tsikli statusu. Tranzaksiya yaradılarkən həmişə
 * `Completed` ilə başlayır; reverse və ya refund prosesi onu terminal statusa
 * keçirir.
 *
 * P-11 / C-9 ilə bağlıdır: əvvəl POS və admin controller-lərində `'completed'`
 * və `'reversed'` magic string-lər idi; bu enum həm yazma, həm müqayisə üçün
 * təmkinli tip qoruması verir.
 */
enum TransactionStatus: string
{
    case Completed = 'completed';   // başlanğıc və yalnız uğurlu satış
    case Refunded  = 'refunded';    // müştəri qaytarması (gələcək feature, hələ tətbiq edilməyib)
    case Reversed  = 'reversed';    // səhv satışın admin/staff tərəfindən ləğvi

    /** Daha çox dəyişikliyə icazə vermə — terminal status. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Refunded, self::Reversed => true,
            self::Completed                => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Tamamlanıb',
            self::Refunded  => 'Qaytarılıb',
            self::Reversed  => 'Ləğv edilib',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
