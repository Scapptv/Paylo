<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deaktiv (is_active=false) istifadəçilər heç bir authenticated route-a
 * daxil ola bilməsin. Tətbiq olunma:
 *
 *   - Web group-a `auth`-dan sonra append olunur (bootstrap/app.php).
 *   - Guest sorğular toxunulmadan ötür.
 *
 * Bloklanan user logout edilir ki, session/cookie təkrar cəhd üçün təmiz olsun.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! (bool) $user->is_active) {
            // API/sanctum token contexti: cari token-i dərhal revoke et ki,
            // mobile cihaz növbəti sorğuda artıq 401 alsın (yenidən login
            // tələb olunsun) və push dispatcher də ölü token-i təmizləyə bilsin.
            $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
            if ($token !== null && method_exists($token, 'delete')) {
                $token->delete();
            }

            // Web session contexti — guard-ı təmiz logout et.
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(403, 'Hesab deaktivdir. Adminstratorla əlaqə saxlayın.');
        }

        return $next($request);
    }
}
