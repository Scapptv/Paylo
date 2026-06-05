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

        // Audit C-10: `config('logging.channels.audit')` array qaytarır və
        // truthy check həm boş array, həm misconfigured value-larda işləyir.
        // `has()` açıq şəkildə açar mövcudluğunu yoxlayır — niyyət aydındır.
        $channel = config()->has('logging.channels.audit')
            ? 'audit'
            : config('logging.default');

        Log::channel($channel)->info('audit.' . $event, $payload);
    }
}
