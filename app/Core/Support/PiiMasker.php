<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * PII (Personally Identifiable Information) maskalama utility-si.
 *
 * Audit Sprint 8 D-6: əvvəl yalnız `Merchant/DashboardController`-də static
 * `maskPhone()` metodu var idi; gələcəkdə email, IBAN və tin də müxtəlif
 * rollara fərqli görünməli ola bilər. Tək utility ilə sinxron saxlanır.
 *
 * Bütün metodlar deterministikdir — eyni input → eyni output. State yoxdur.
 */
final class PiiMasker
{
    /**
     * Telefon nömrəsi: ilk 4 və son 3 simvol açıq, ortası `*`.
     *  `+994501234567` (13)   → `+994******567`
     *  `+9941234567890` (14)  → `+994*******890`
     *  `12345` (5)            → `*****`           (çox qısa, tamamilə maskala)
     */
    public static function phone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 7) {
            return str_repeat('*', $len);
        }
        return substr($phone, 0, 4) . str_repeat('*', $len - 7) . substr($phone, -3);
    }

    /**
     * Email-ın local hissəsini maskalama: `user@example.com` → `u**r@example.com`.
     * Domain açıq qalır (verifiable, lakin individual identity gizlədilir).
     */
    public static function email(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 2) {
            return str_repeat('*', strlen($email));
        }
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at);

        if (strlen($local) <= 2) {
            return str_repeat('*', strlen($local)) . $domain;
        }
        return $local[0] . str_repeat('*', strlen($local) - 2) . substr($local, -1) . $domain;
    }

    /**
     * IBAN: ilk 4 simvol (ölkə+check) və son 4 simvol açıq, ortası `*`.
     *  `AZ21NABZ00000000137010001944` (28) → `AZ21********************1944`
     */
    public static function iban(string $iban): string
    {
        $len = strlen($iban);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($iban, 0, 4) . str_repeat('*', $len - 8) . substr($iban, -4);
    }
}
