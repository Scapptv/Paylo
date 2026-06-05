<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Merchant həyat tsikli statusu. Yalnız `Active` mağaza canlı POS axınına icazə verir.
 * Status keçidləri admin auditi tərəfindən izlənir (Sprint 4-dəki MerchantStatusChanged event).
 */
enum MerchantStatus: string
{
    case Active   = 'active';    // canlı, POS əməliyyatlarına icazə verilir
    case Pending  = 'pending';   // onboarding tamamlanmayıb, ledger yazıla bilməz
    case Paused   = 'paused';    // müvəqqəti dondurulub (məs ödəniş gecikməsi)
    case Revoked  = 'revoked';   // birdəfəlik bağlanıb, yalnız oxuma

    /** POS / API endpoint-ləri yalnız bu statusda satışı qəbul edir. */
    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active  => 'Aktiv',
            self::Pending => 'Gözləmədə',
            self::Paused  => 'Dayandırılıb',
            self::Revoked => 'Ləğv edilib',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
