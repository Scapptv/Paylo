<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `X-RateLimit-*` header-lərini API cavabına əlavə edir. Mobile/Flutter app
 * proaktiv backoff strategiyası tətbiq edə bilsin — sənədə baxmadan limit
 * yaxınlaşmasını görsün.
 *
 * Audit Sprint 8 T-1: API_THROTTLE_KEY istifadəçi tərəfindən təxmin edilmir —
 * Sanctum token-ə bağlı default throttle key (user_id) və endpoint qrupuna görə
 * fərqli limitlərə malikdir. Bu middleware standart Laravel throttle pattern-i
 * izləyir: `Retry-After` header yalnız 429 cavabında, `X-RateLimit-Limit` və
 * `X-RateLimit-Remaining` hər API cavabında qoyulur.
 *
 * Default: yalnız `/api/*` route-ları üçün aktiv.
 *
 * Audit 2026-06-04 API-1: bu middleware artıq route-səviyyəli `throttle:N,M`
 * tərəfindən qoyulan header-ləri ƏZMİR — yalnız throttle-suz route üçün fallback
 * verir. Əvvəllər hardcoded 60 + heç vaxt increment olunmayan `api|{id}` key ilə
 * düzgün dəyərləri üzərinə yazırdı.
 */
final class AddRateLimitHeaders
{
    private const HEADER_LIMIT     = 'X-RateLimit-Limit';
    private const HEADER_REMAINING = 'X-RateLimit-Remaining';
    private const HEADER_RESET     = 'X-RateLimit-Reset';

    public function handle(Request $request, Closure $next, int $maxAttempts = 60, int $decaySeconds = 60): Response
    {
        $key = $this->resolveKey($request);

        /** @var Response $response */
        $response = $next($request);

        // Audit 2026-06-04 API-1: route-səviyyəli `throttle:N,M` (Laravel
        // ThrottleRequests) artıq düzgün `X-RateLimit-Limit/Remaining`-i hər cavabda
        // qoyur — qr=10, pos=120, login=5, default=60. Əvvəllər bu middleware onları
        // hardcoded 60 + increment olunmayan `api|{id}` key ilə əzirdi, ona görə
        // bütün route-lar 60 göstərirdi və mobil M-7 parser / POSNET backoff səhv
        // oxuyurdu. İndi mövcud header varsa toxunmuruq.
        if ($response->headers->has(self::HEADER_LIMIT)) {
            return $response;
        }

        // Fallback: throttle-suz route üçün (hazırda bütün api route-ları
        // throttle-lıdır; bu, gələcək route-lar üçün müdafiə qatıdır).
        $attempts  = RateLimiter::attempts($key);
        $remaining = max(0, $maxAttempts - $attempts);
        $resetIn   = RateLimiter::availableIn($key);

        $response->headers->set(self::HEADER_LIMIT, (string) $maxAttempts);
        $response->headers->set(self::HEADER_REMAINING, (string) $remaining);
        if ($resetIn > 0) {
            $response->headers->set(self::HEADER_RESET, (string) $resetIn);
        }

        return $response;
    }

    /**
     * Throttle key — token sahibinin id-si, anonim sorğu üçün IP. Bu Laravel-in
     * default `throttle:api` middleware-i ilə eyni strategiya.
     */
    private function resolveKey(Request $request): string
    {
        $user = $request->user();
        return 'api|' . ($user ? (string) $user->getAuthIdentifier() : (string) $request->ip());
    }
}
