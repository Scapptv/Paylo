<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-da `->middleware('role:admin')` kimi istifadə olunur.
 * Birdən çox rol qəbul edə bilir: `role:admin,merchant_owner`.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Audit H-1: route definition-dakı naməlum rol enum-a uyğun gəlmirsə
        // `UserRole::from()` `\ValueError` atırdı — stack-trace dev üçün qaranlıq
        // idi. `tryFrom + LogicException` dərhal route konfiqurasiya problemini
        // adı ilə birgə görsədir; production-da bu fail-fast davranışdır
        // (silent 403 yox).
        $allowed = [];
        foreach ($roles as $r) {
            $role = UserRole::tryFrom($r);
            if ($role === null) {
                throw new \LogicException(sprintf(
                    "EnsureRole middleware-i naməlum rol aldı: '%s'. Etibarlı rollar: %s.",
                    $r,
                    implode(', ', UserRole::values()),
                ));
            }
            $allowed[] = $role;
        }

        if (! in_array($user->role, $allowed, strict: true)) {
            abort(403, 'Bu səhifəyə daxil olmaq üçün icazəniz yoxdur.');
        }

        return $next($request);
    }
}
