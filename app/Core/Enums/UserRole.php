<?php

declare(strict_types=1);

namespace App\Core\Enums;

/**
 * Bütün sistemdəki rollar. Hər istifadəçi yalnız bir roluna sahibdir,
 * lakin bir merchant-da müxtəlif rollarda olan birdən çox istifadəçi ola bilər.
 *
 * Login sonrası redirect qaydası `Modules\Auth\Services\RoleRouter` daxilindədir.
 */
enum UserRole: string
{
    case Admin          = 'admin';
    case MerchantOwner  = 'merchant_owner';
    case MerchantStaff  = 'merchant_staff';
    case Cashier        = 'cashier';
    case PosTerminal    = 'pos_terminal';
    case Customer       = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::Admin         => 'Sistem Administratoru',
            self::MerchantOwner => 'Merchant Sahibi',
            self::MerchantStaff => 'Merchant İşçisi',
            self::Cashier       => 'Kassir',
            self::PosTerminal   => 'POS Terminal',
            self::Customer      => 'Müştəri',
        };
    }

    /** Login sonrası yönləndiriləcəyi route adı */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin                              => 'admin.dashboard',
            self::MerchantOwner, self::MerchantStaff => 'merchant.dashboard',
            self::Cashier                            => 'cashier.shift',
            self::PosTerminal                        => 'pos.sale',
            self::Customer                           => 'user.wallet',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
