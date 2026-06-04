<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Idempotency-Key` header dəstəyi — Stripe/Square pattern-i.
 *
 * POSNET (və ya hər hansı M2M client) eyni `Idempotency-Key` header dəyəri ilə
 * eyni endpoint-ə müraciət etsə, ilk uğurlu (2xx) cavab cache-də saxlanır
 * və sonrakı identik sorğular üçün replay olunur. Bu sayədə şəbəkə uğursuzluğu
 * ilə əlaqəli retry-lar duplicate yan-effekt yaratmaz.
 *
 * Cache açarı: `idem:pos:{user_id}:{idempotency_key}`. Açar token sahibi user
 * üzərindən izolyasiyalıdır — bir merchant-ın açar fəzası başqa merchant-ın
 * cache-ini görə bilməz.
 *
 * Body hash mismatch (eyni açar, fərqli payload) `422 Unprocessable Entity`
 * qaytarır — bu güclü siqnaldır ki, client-də açar idarəsi qırılıb.
 *
 * TTL: 24 saat. Bundan uzun müddət sonra eyni açarla gələn sorğu yeni iş kimi
 * qəbul edilir; lakin domain-level idempotency (`merchant_id + receipt_no`)
 * yenə də duplicate-i tutar.
 */
final class IdempotencyKey
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            // Header opsionaldır — domain idempotency (receipt_no) yenə də qoruyacaq.
            return $next($request);
        }

        if (! $this->isValidKey($key)) {
            return response()->json([
                'message' => 'Idempotency-Key formatı yanlışdır. 8–128 simvol, yalnız [A-Za-z0-9_-].',
                'errors'  => ['Idempotency-Key' => ['Yanlış format.']],
            ], 422);
        }

        // Cache namespace per-token, NOT per-user. Bir merchant bir POS user-i
        // (`pos@<code>.api`) altında bir neçə terminal token saxlayır — token-lər
        // ayrı namespace-də olmalıdır ki, Terminal A-nın Idempotency-Key fəzası
        // Terminal B-yə "sızmasın" (eyni açar + eyni body collision).
        // Token yoxdursa (testlər və ya middleware sıra pozulması) user-id-yə fallback.
        $token    = $request->user()?->currentAccessToken();
        $tokenId  = $token?->id ?? ('u' . (int) ($request->user()?->id ?? 0));
        $bodyHash = hash('sha256', $request->getContent() ?: '');
        $cacheKey = "idem:pos:t:{$tokenId}:" . hash('sha256', $key);

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            // Eyni açar, fərqli body — client bug-ı. Açıq 422 ilə xəbər ver.
            if (! hash_equals($cached['body_hash'], $bodyHash)) {
                return response()->json([
                    'message' => 'Idempotency-Key əvvəlki sorğudan fərqli body ilə təkrar istifadə edildi.',
                    'errors'  => ['Idempotency-Key' => ['Eyni açarın iki fərqli body ilə işlənməsinə icazə verilmir.']],
                ], 422);
            }

            // Cached uğurlu cavabı replay et — POSNET retry vəziyyətində eyni nəticə.
            $response = response()->json($cached['body'], $cached['status']);
            $response->headers->set('Idempotent-Replay', 'true');

            return $response;
        }

        $response = $next($request);

        // Yalnız uğurlu cavabları cache-lə. 4xx/5xx-i cache-ləmək client-i sındıra bilər
        // (client retry edib müvəqqəti xətanı keçə bilməsin).
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $body = $response->getContent();
            $decoded = $body !== false ? json_decode($body, true) : null;

            Cache::put($cacheKey, [
                'status'    => $response->getStatusCode(),
                'body'      => $decoded,
                'body_hash' => $bodyHash,
            ], self::TTL_SECONDS);
        }

        return $response;
    }

    private function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $key);
    }
}
