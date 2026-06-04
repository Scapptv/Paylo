<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * V2 hardening — per-token HMAC body signature.
 *
 * Sızdırılmış bearer token-ə qarşı ikinci müdafiə xətti:
 *  - Bearer attacker-ə girişə vesica versə, HMAC secret olmadan body-ni
 *    manipulyasiya edə bilməz (məs. sale_amount_cents-i artırmaq).
 *
 * Sxem:
 *   Headers:
 *     X-Paylo-Timestamp: <unix-timestamp>
 *     X-Paylo-Signature: sha256=<hex32>
 *
 *   payload      = X-Paylo-Timestamp . "." . request_body
 *   expected_sig = hash_hmac('sha256', payload, token.hmac_secret)
 *
 * Replay protection: timestamp cari serverdən ±300 saniyə kənarda olmamalıdır.
 *
 * Backward compat: token-in `hmac_secret` sütunu null isə middleware imza
 * yoxlamır (köhnə client-lər qırılmır). Yeni `--require-hmac` flag-i ilə
 * verilən token-lər secret-i doldurur və imza məcburi olur.
 *
 * Cavablar:
 *   401 — Unauthenticated (eyni Sanctum 401 status, fərq audit log-da)
 *   400 — Malformed headers (timestamp not integer, signature wrong format)
 */
final class VerifyHmacSignature
{
    private const TIMESTAMP_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Defensive: middleware sıra pozulması — auth:sanctum-dan əvvəl gəldikdə.
        if ($token === null || ! isset($token->hmac_secret)) {
            return $next($request);
        }

        // Backward compat: secret yoxdursa imza tələb etmirik.
        $secret = (string) ($token->hmac_secret ?? '');
        if ($secret === '') {
            return $next($request);
        }

        $timestamp = $request->header('X-Paylo-Timestamp');
        $signature = $request->header('X-Paylo-Signature');

        if ($timestamp === null || $signature === null) {
            return $this->reject('HMAC headers məcburidir.', 'missing_headers');
        }

        if (! ctype_digit((string) $timestamp)) {
            return $this->reject('X-Paylo-Timestamp integer olmalıdır.', 'malformed_timestamp', 400);
        }

        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::TIMESTAMP_SKEW_SECONDS) {
            return $this->reject('Timestamp ±5 dəq pəncərəsindən kənardır.', 'skew');
        }

        $expectedPrefix = 'sha256=';
        if (! str_starts_with((string) $signature, $expectedPrefix)) {
            return $this->reject('X-Paylo-Signature format: sha256=<hex>', 'malformed_signature', 400);
        }

        $providedHex = substr((string) $signature, strlen($expectedPrefix));
        $payload     = $timestamp . '.' . ($request->getContent() ?: '');
        $expectedHex = hash_hmac('sha256', $payload, $secret);

        // Constant-time müqayisə — timing-side-channel qoruyucu.
        if (! hash_equals($expectedHex, $providedHex)) {
            return $this->reject('HMAC signature uyğunsuzdur.', 'mismatch');
        }

        return $next($request);
    }

    private function reject(string $message, string $reason, int $status = 401): Response
    {
        return response()->json([
            'message' => $message,
            'errors'  => ['X-Paylo-Signature' => [$reason]],
        ], $status);
    }
}
