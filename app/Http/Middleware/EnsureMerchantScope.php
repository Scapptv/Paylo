<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant və Cashier rollu istifadəçilər yalnız öz merchant-larının data-sını görə bilər.
 * Bu middleware request-i `merchant_id`-ə bind edir və controller-də istifadə üçün
 * `$request->attributes->get('merchant_id')` təmin edir.
 */
class EnsureMerchantScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $rolesNeedMerchant = [
            UserRole::MerchantOwner,
            UserRole::MerchantStaff,
            UserRole::Cashier,
            UserRole::PosTerminal,
        ];

        if (in_array($user->role, $rolesNeedMerchant, strict: true)) {
            if (! $user->merchant_id) {
                abort(403, 'Hesabınız hər hansı bir merchant-a bağlı deyil.');
            }

            $request->attributes->set('merchant_id', $user->merchant_id);
        }

        // Defense-in-depth: bu middleware tətbiq olunan hər route üçün scope məcburidir.
        // Əgər istifadəçi yuxarıdakı siyahıda deyilsə (məs. Admin POS route-una düşür),
        // səssizcə 0 default ilə davam etmək əvəzinə fail-fast et.
        if (! $request->attributes->has('merchant_id')) {
            abort(403, 'Bu endpoint üçün merchant scope tələb olunur.');
        }

        return $next($request);
    }
}
