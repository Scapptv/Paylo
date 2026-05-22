<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Core\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Rotating QR token generator + verifier.
 *
 * Token format:   qr1.{user_customer_qr}.{exp_unix}.{hmac16}
 *   - version  = "qr1" (fixed)
 *   - exp_unix = absolute expiry timestamp (Unix seconds)
 *   - hmac16   = substr( hash_hmac('sha256', "{user_qr}.{exp}", APP_KEY), 0, 16 )
 *
 * Verify pipeline:
 *   1. Format / version yoxlanışı
 *   2. HMAC re-compute + sabit-zaman müqayisəsi (hash_equals)
 *   3. exp_unix > now()
 *   4. Replay protection — cache key `qr_used:{hmac16}` mövcuddursa rədd
 */
final class RotatingQrService
{
    public const VERSION = 'qr1';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {
    }

    /**
     * @return array{token: string, expires_at: string}
     */
    public function generate(User $user, int $ttlSeconds = 30): array
    {
        $userQr = (string) $user->customer_qr;
        $exp    = Carbon::now()->getTimestamp() + $ttlSeconds;
        $hmac   = $this->hmac($userQr, $exp);

        return [
            'token'      => sprintf('%s.%s.%d.%s', self::VERSION, $userQr, $exp, $hmac),
            'expires_at' => Carbon::createFromTimestamp($exp)->toIso8601String(),
        ];
    }

    /**
     * @return array{valid: bool, user_qr: ?string, reason: ?string, hmac?: string, exp?: int}
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 4) {
            return $this->invalid('malformed');
        }

        [$version, $userQr, $expStr, $hmac] = $parts;

        if ($version !== self::VERSION) {
            return $this->invalid('version_mismatch');
        }

        if ($userQr === '' || ! ctype_digit($expStr) || strlen($hmac) !== 16) {
            return $this->invalid('malformed');
        }

        $exp      = (int) $expStr;
        $expected = $this->hmac($userQr, $exp);

        if (! hash_equals($expected, $hmac)) {
            return $this->invalid('bad_signature');
        }

        if ($exp <= Carbon::now()->getTimestamp()) {
            return $this->invalid('expired');
        }

        if ($this->isUsed($hmac)) {
            return $this->invalid('replay');
        }

        return [
            'valid'   => true,
            'user_qr' => $userQr,
            'reason'  => null,
            'hmac'    => $hmac,
            'exp'     => $exp,
        ];
    }

    /**
     * Uğurlu redeem-dən sonra cashier endpoint-i çağırır ki, eyni token
     * yenidən istifadə oluna bilməsin.
     */
    public function markUsed(string $hmac, int $ttlSeconds = 60): void
    {
        $this->cache->put($this->usedKey($hmac), 1, $ttlSeconds);
    }

    public function isUsed(string $hmac): bool
    {
        return $this->cache->has($this->usedKey($hmac));
    }

    private function hmac(string $userQr, int $exp): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return substr(hash_hmac('sha256', $userQr . '.' . $exp, $key), 0, 16);
    }

    private function usedKey(string $hmac): string
    {
        return 'qr_used:' . $hmac;
    }

    /**
     * @return array{valid: false, user_qr: null, reason: string}
     */
    private function invalid(string $reason): array
    {
        return ['valid' => false, 'user_qr' => null, 'reason' => $reason];
    }

    public static function make(): self
    {
        return new self(Cache::store());
    }
}
