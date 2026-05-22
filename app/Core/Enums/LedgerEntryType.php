<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Ledger immutable append-only-dur. Heç bir entry update və ya delete olunmur.
 * Reversal/refund yeni bir entry kimi yazılır və "reverses" sahəsi ilə original-a istinad edir.
 */
enum LedgerEntryType: string
{
    case Earn       = 'earn';        // satışdan bonus qazanma
    case Redeem     = 'redeem';      // bonus xərcləmə
    case Refund     = 'refund';      // tam refund — earn-i geri qaytarır
    case Reversal   = 'reversal';    // səhv tranzaksiyanın ləğvi
    case Expire     = 'expire';      // bucket-də vaxtı bitmiş bonusun silinməsi
    case Adjustment = 'adjustment';  // admin tərəfindən manual düzəliş
    case Transfer   = 'transfer';    // istisna hal: admin onayı ilə bucket-lər arası

    public function isCredit(): bool
    {
        return match ($this) {
            self::Earn, self::Adjustment, self::Transfer => true,
            default                                       => false,
        };
    }

    public function isDebit(): bool
    {
        return ! $this->isCredit();
    }

    public function label(): string
    {
        return match ($this) {
            self::Earn       => 'Qazanma',
            self::Redeem     => 'Xərcləmə',
            self::Refund     => 'Refund',
            self::Reversal   => 'Reversal',
            self::Expire     => 'Müddəti bitib',
            self::Adjustment => 'Manual düzəliş',
            self::Transfer   => 'Transfer',
        };
    }
}
