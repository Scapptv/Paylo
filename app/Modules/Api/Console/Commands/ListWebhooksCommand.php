<?php

declare(strict_types=1);

namespace App\Modules\Api\Console\Commands;

use App\Core\Models\Merchant;
use App\Modules\Api\Models\WebhookDelivery;
use App\Modules\Api\Models\WebhookEndpoint;
use Illuminate\Console\Command;

/**
 * Webhook endpoint-lərinin siyahısı + delivery xülasəsi.
 *
 *   php artisan pos:list-webhooks
 *   php artisan pos:list-webhooks --merchant=m_412
 */
final class ListWebhooksCommand extends Command
{
    protected $signature = 'pos:list-webhooks
                            {--merchant= : Yalnız konkret merchant}';

    protected $description = 'Webhook endpoint və son delivery xülasəsi.';

    public function handle(): int
    {
        $merchantCode = (string) ($this->option('merchant') ?? '');

        $query = WebhookEndpoint::with('merchant:id,code,name');
        if ($merchantCode !== '') {
            $merchant = Merchant::where('code', $merchantCode)->first();
            if ($merchant === null) {
                $this->error("Merchant '{$merchantCode}' tapılmadı.");

                return self::FAILURE;
            }
            $query->where('merchant_id', $merchant->id);
        }

        $endpoints = $query->orderByDesc('id')->get();

        if ($endpoints->isEmpty()) {
            $this->line('Webhook endpoint yoxdur.');

            return self::SUCCESS;
        }

        $rows = $endpoints->map(function (WebhookEndpoint $e): array {
            $delivered = WebhookDelivery::where('endpoint_id', $e->id)
                ->where('status', WebhookDelivery::STATUS_DELIVERED)->count();
            $failed = WebhookDelivery::where('endpoint_id', $e->id)
                ->where('status', WebhookDelivery::STATUS_FAILED)->count();
            $pending = WebhookDelivery::where('endpoint_id', $e->id)
                ->where('status', WebhookDelivery::STATUS_PENDING)->count();

            return [
                'id'        => $e->id,
                'merchant'  => $e->merchant?->code ?? '-',
                'name'      => $e->name,
                'url'       => mb_strimwidth($e->url, 0, 60, '…'),
                'events'    => implode(',', $e->events),
                'active'    => $e->active ? 'yes' : 'no',
                'delivered' => $delivered,
                'failed'    => $failed,
                'pending'   => $pending,
            ];
        })->all();

        $this->table(
            ['ID', 'Merchant', 'Ad', 'URL', 'Events', 'Active', 'Delivered', 'Failed', 'Pending'],
            $rows,
        );

        return self::SUCCESS;
    }
}
