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

        $allowed = array_map(
            fn (string $r) => UserRole::from($r),
            $roles,
        );

        if (! in_array($user->role, $allowed, strict: true)) {
            abort(403, 'Bu səhifəyə daxil olmaq üçün icazəniz yoxdur.');
        }

        return $next($request);
    }
}
