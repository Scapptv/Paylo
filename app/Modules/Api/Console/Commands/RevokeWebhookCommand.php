<?php

declare(strict_types=1);

namespace App\Modules\Api\Console\Commands;

use App\Core\Services\AuditLogger;
use App\Modules\Api\Models\WebhookEndpoint;
use Illuminate\Console\Command;

/**
 * Webhook endpoint-i revoke edir (deaktiv edir və ya silir).
 *
 *   php artisan pos:revoke-webhook --id=42                  # deactivate (default)
 *   php artisan pos:revoke-webhook --id=42 --delete --force # hard delete (deliveries də cascade silinir)
 */
final class RevokeWebhookCommand extends Command
{
    protected $signature = 'pos:revoke-webhook
                            {--id= : Endpoint ID}
                            {--delete : Hard delete (default: yalnız active=false)}
                            {--reason= : Səbəb (audit log)}
                            {--force : Təsdiq sorğusunu atla}';

    protected $description = 'Webhook endpoint-i deaktiv edir və ya silir.';

    public function handle(AuditLogger $audit): int
    {
        $id     = $this->option('id');
        $delete = (bool) $this->option('delete');
        $reason = $this->option('reason');
        $force  = (bool) $this->option('force');

        if ($id === null || $id === '') {
            $this->error('--id məcburidir.');

            return self::INVALID;
        }

        $endpoint = WebhookEndpoint::find((int) $id);
        if ($endpoint === null) {
            $this->error("Endpoint ID={$id} tapılmadı.");

            return self::FAILURE;
        }

        $action = $delete ? 'silmək' : 'deaktiv etmək';
        $this->warn("Endpoint #{$endpoint->id} '{$endpoint->name}' ({$endpoint->url}) — {$action} istənir.");

        if (! $force && ! $this->confirm('Davam edək?', false)) {
            $this->line('Ləğv edildi.');

            return self::SUCCESS;
        }

        $event = 'api.pos.webhook.deactivated';
        $endpointId = $endpoint->id;
        $name = $endpoint->name;

        if ($delete) {
            $endpoint->delete();
            $event = 'api.pos.webhook.deleted';
            $this->info("Endpoint #{$endpointId} silindi (delivery sətrləri də cascade silindi).");
        } else {
            $endpoint->update(['active' => false]);
            $this->info("Endpoint #{$endpointId} deaktiv edildi. Növbəti event-lər ona göndərilməyəcək.");
        }

        $audit->log($event, [
            'endpoint_id' => $endpointId,
            'name'        => $name,
            'reason'      => $reason,
        ]);

        return self::SUCCESS;
    }
}
