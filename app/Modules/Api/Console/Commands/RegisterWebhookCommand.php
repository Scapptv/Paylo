<?php

declare(strict_types=1);

namespace App\Modules\Api\Console\Commands;

use App\Core\Models\Merchant;
use App\Core\Services\AuditLogger;
use App\Modules\Api\Models\WebhookEndpoint;
use Illuminate\Console\Command;

/**
 * POSNET-ə (və ya başqa receiver-ə) webhook endpoint qeyd edir.
 *
 * Misal:
 *   php artisan pos:register-webhook \
 *     --merchant=m_412 \
 *     --name=posnet-prod \
 *     --url=https://posnet.example/loyalty-events \
 *     --events=admin_reverse,bucket_expire
 *
 * HMAC secret avtomatik generasiya olunur və çıxışda BİR DƏFƏ göstərilir.
 * Sahibi onu receiver tərəfdə Vault-da saxlamalıdır.
 */
final class RegisterWebhookCommand extends Command
{
    protected $signature = 'pos:register-webhook
                            {--merchant= : Merchant kodu}
                            {--name= : Endpoint etiketi}
                            {--url= : Receiver URL (https tövsiyyə olunur)}
                            {--events= : Vergüllə ayrılmış event-lər: admin_reverse,bucket_expire}';

    protected $description = 'POSNET (və ya başqa) tərəfdə webhook endpoint qeyd edir.';

    private const ALLOWED_EVENTS = ['admin_reverse', 'bucket_expire'];

    public function handle(AuditLogger $audit): int
    {
        $merchantCode = (string) ($this->option('merchant') ?? '');
        $name         = (string) ($this->option('name') ?? '');
        $url          = (string) ($this->option('url') ?? '');
        $eventsRaw    = (string) ($this->option('events') ?? '');

        if ($merchantCode === '' || $name === '' || $url === '' || $eventsRaw === '') {
            $this->error('--merchant, --name, --url, --events məcburidir.');

            return self::INVALID;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error("URL forması yanlışdır: {$url}");

            return self::INVALID;
        }

        $events = array_values(array_filter(array_map('trim', explode(',', $eventsRaw))));
        $unknown = array_diff($events, self::ALLOWED_EVENTS);
        if ($unknown !== []) {
            $this->error('Tanınmayan event: ' . implode(',', $unknown)
                . '. İcazə verilənlər: ' . implode(',', self::ALLOWED_EVENTS));

            return self::INVALID;
        }

        $merchant = Merchant::where('code', $merchantCode)->first();
        if ($merchant === null) {
            $this->error("Merchant '{$merchantCode}' tapılmadı.");

            return self::FAILURE;
        }

        $secret = bin2hex(random_bytes(32));
        $endpoint = WebhookEndpoint::create([
            'merchant_id' => $merchant->id,
            'name'        => $name,
            'url'         => $url,
            'hmac_secret' => $secret,
            'events'      => $events,
            'active'      => true,
        ]);

        $audit->log('api.pos.webhook.registered', [
            'endpoint_id'  => $endpoint->id,
            'merchant_id'  => $merchant->id,
            'merchant_code'=> $merchant->code,
            'name'         => $name,
            'url'          => $url,
            'events'       => $events,
        ]);

        $this->newLine();
        $this->line('====================================================================');
        $this->line('WEBHOOK ENDPOINT — Secret BİR DƏFƏ göstərilir.');
        $this->line('====================================================================');
        $this->newLine();
        $this->line("ID            : {$endpoint->id}");
        $this->line("Merchant      : {$merchant->code} ({$merchant->name})");
        $this->line("Ad            : {$name}");
        $this->line("URL           : {$url}");
        $this->line("Event-lər     : " . implode(', ', $events));
        $this->newLine();
        $this->line("HMAC secret   : {$secret}");
        $this->newLine();
        $this->line('Receiver tərəfdə hər inbound webhook-da yoxlayın:');
        $this->line('  payload      = X-Paylo-Timestamp + "." + raw_body');
        $this->line('  expected_sig = hmac_sha256(payload, secret)');
        $this->line('  Match X-Paylo-Signature: sha256=<expected> (constant-time)');
        $this->line('  X-Paylo-Event-Id POSNET-də idempotency açarı kimi istifadə edin.');
        $this->newLine();

        return self::SUCCESS;
    }
}
