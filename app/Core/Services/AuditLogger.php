<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Struktur-loq yazıcı — dual-write.
 *
 * Hər hadisəni İKİ yerə yazır:
 *  1) `audit` log channel-ı varsa ora, yoxdursa default channel-a (fayl/stream);
 *  2) DB-əsaslı `audit_logs` cədvəlinə (roadmap Phase 3.1) — admin panelində
 *     sorğulanabilir/filtrlənən görünüş üçün.
 *
 * DB yazısı DEFENSIV-dir: cədvəl yoxdursa (ilkin install/migration) və ya hər
 * hansı DB xətası baş verərsə, biznes əməliyyatı SINDIRILMIR — fayl-loquna
 * düşürük (audit yazısı onsuz da log channel-da mövcuddur).
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

        $this->persist($event, $context, $request, $channel);
    }

    /**
     * DB-əsaslı `audit_logs` cədvəlinə yaz (Phase 3.1 dual-write).
     *
     * `Schema::hasTable` ilə öncədən yoxlamaq həm gərəksiz INSERT cəhdini, həm də
     * (xüsusən PostgreSQL-də) transaction içində uğursuz statement-in bütün
     * transaction-ı "abort" etmə riskini aradan qaldırır. Yenə də hər ehtimala
     * qarşı try/catch — audit heç vaxt çağıran əməliyyatı sındırmamalıdır.
     *
     * @param  array<string, mixed>  $context
     */
    private function persist(string $event, array $context, ?Request $request, string $channel): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            AuditLog::create([
                'event'      => $event,
                'actor_id'   => $request?->user()?->id,
                'context'    => $context,
                'ip'         => $request?->ip(),
                'user_agent' => $request !== null ? (string) $request->userAgent() : null,
            ]);
        } catch (\Throwable $e) {
            // DB audit uğursuz — fayl-loquna düş, lakin çağıranı sındırma.
            Log::channel($channel)->warning('audit.persist_failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
