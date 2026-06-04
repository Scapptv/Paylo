<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Core\Services\AuditLogger;
use App\Modules\Api\Models\WebhookDelivery;
use App\Modules\Api\Models\WebhookEndpoint;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Synchronous webhook delivery to subscribed endpoints.
 *
 * Niyə sync (queue yox) v1-də:
 *  - Event volume hələ aşağı (admin reverse + nightly bucket expire),
 *  - Sync delivery + DB-də pending record + manual redeliver komandası
 *    operatorun mövcud workflow-suna sığır.
 *  - Queue (Horizon + Redis) əlavə dep stack-i. V2-yə deferred.
 *
 * Failure path: HTTP 4xx/5xx və ya network exception delivery-ni `failed`
 * status-a yerləşdirir, response body və status code DB-də saxlanır. Operator
 * `pos:webhook-redeliver --id=X` ilə yenidən cəhd edə bilər.
 *
 * Signing: V2 hardening ilə eyni sxem — HMAC-SHA256 over `ts + "." + body`.
 * Headers:
 *   X-Paylo-Event:      <event_type>
 *   X-Paylo-Event-Id:   <ulid>          POSNET idempotency açarı
 *   X-Paylo-Timestamp:  <unix>
 *   X-Paylo-Signature:  sha256=<hex>
 */
final class WebhookSender
{
    private const TIMEOUT_SECONDS = 8;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Bir event-i merchant-ın aktiv və abunə endpoint-lərinə göndər.
     *
     * Return: yaradılan delivery sətrlərinin sayı (subscribed endpoint count).
     * Delivery uğursuz olarsa exception atılmır — qeyd DB-də qalır, audit log
     * yazılır, çağıran ledger əməliyyatını davam etdirir.
     */
    public function emit(int $merchantId, string $eventType, array $payload): int
    {
        $endpoints = WebhookEndpoint::where('merchant_id', $merchantId)
            ->where('active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $e) => $e->subscribesTo($eventType));

        $count = 0;
        foreach ($endpoints as $endpoint) {
            $this->deliverOnce($endpoint, $eventType, $payload);
            $count++;
        }

        return $count;
    }

    /**
     * Endpoint-ə bir dəfə cəhd et — uğurlu olsa delivered, olmasa failed.
     */
    public function deliverOnce(WebhookEndpoint $endpoint, string $eventType, array $payload): WebhookDelivery
    {
        $eventId = (string) Str::ulid();

        $delivery = WebhookDelivery::create([
            'event_id'       => $eventId,
            'endpoint_id'    => $endpoint->id,
            'event_type'     => $eventType,
            'payload'        => $payload,
            'status'         => WebhookDelivery::STATUS_PENDING,
        ]);

        $this->attempt($delivery);

        return $delivery->refresh();
    }

    /**
     * Mövcud delivery-yə bir cəhd daha et — manual `pos:webhook-redeliver`
     * yolu da bu metodu çağırır.
     */
    public function retry(WebhookDelivery $delivery): WebhookDelivery
    {
        if ($delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return $delivery;
        }
        $this->attempt($delivery);

        return $delivery->refresh();
    }

    private function attempt(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;
        $bodyJson = json_encode([
            'event_id'   => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'occurred_at'=> now()->toIso8601String(),
            'data'       => $delivery->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ts  = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . $bodyJson, $endpoint->hmac_secret);

        $headers = [
            'Content-Type'      => 'application/json',
            'Accept'            => 'application/json',
            'X-Paylo-Event'     => $delivery->event_type,
            'X-Paylo-Event-Id'  => $delivery->event_id,
            'X-Paylo-Timestamp' => $ts,
            'X-Paylo-Signature' => 'sha256=' . $sig,
            'User-Agent'        => 'Paylo-Webhook/1.0',
        ];

        $delivery->update([
            'attempt_count'   => $delivery->attempt_count + 1,
            'last_attempt_at' => now(),
        ]);

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withBody($bodyJson, 'application/json')
                ->post($endpoint->url);

            $delivery->update([
                'last_response_status' => $response->status(),
                'last_response_body'   => mb_substr((string) $response->body(), 0, 2000),
            ]);

            if ($response->successful()) {
                $delivery->update([
                    'status'       => WebhookDelivery::STATUS_DELIVERED,
                    'delivered_at' => now(),
                ]);
                $this->audit->log('api.pos.webhook.delivered', [
                    'endpoint_id' => $endpoint->id,
                    'merchant_id' => $endpoint->merchant_id,
                    'event_id'    => $delivery->event_id,
                    'event_type'  => $delivery->event_type,
                    'status_code' => $response->status(),
                    'attempt'     => $delivery->attempt_count,
                ]);

                return;
            }

            // Non-2xx — receiver ya reject edib (4xx) ya da xəta verib (5xx).
            $delivery->update(['status' => WebhookDelivery::STATUS_FAILED]);
            $this->audit->log('api.pos.webhook.failed', [
                'endpoint_id' => $endpoint->id,
                'event_id'    => $delivery->event_id,
                'status_code' => $response->status(),
                'attempt'     => $delivery->attempt_count,
            ]);
        } catch (Throwable $e) {
            // Network / TLS / DNS failure.
            $delivery->update([
                'status'             => WebhookDelivery::STATUS_FAILED,
                'last_response_body' => 'EXCEPTION: ' . mb_substr($e->getMessage(), 0, 1900),
            ]);
            Log::warning('webhook.delivery.exception', [
                'endpoint_id' => $endpoint->id,
                'event_id'    => $delivery->event_id,
                'message'     => $e->getMessage(),
            ]);
            $this->audit->log('api.pos.webhook.failed', [
                'endpoint_id' => $endpoint->id,
                'event_id'    => $delivery->event_id,
                'reason'      => 'exception',
                'attempt'     => $delivery->attempt_count,
            ]);
        }
    }
}
