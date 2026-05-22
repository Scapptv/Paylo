<?php

declare(strict_types=1);

namespace App\Core\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Minimal struktur-loq yazıcı.
 *
 * Hadisələri `audit` log channel-ı varsa ora, yoxdursa default channel-a
 * JSON-friendly context ilə yazır. Sonradan DB-əsaslı audit_logs cədvəlinə
 * köçürmək olar — interfeys saxlanılır.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $event, array $context = [], ?Request $request = null): void
    {
        $payload = [
            'event'      => $event,
            'occurred_at' => now()->toIso8601String(),
        ] + $context;

        if ($request) {
            $payload['ip']         = $request->ip();
            $payload['user_agent'] = (string) $request->userAgent();
        }

        $channel = config('logging.channels.audit') ? 'audit' : config('logging.default');

        Log::channel($channel)->info('audit.' . $event, $payload);
    }
}
