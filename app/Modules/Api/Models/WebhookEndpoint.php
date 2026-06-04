<?php

declare(strict_types=1);

namespace App\Modules\Api\Models;

use App\Core\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-merchant outbound webhook endpoint.
 *
 * @property int    $id
 * @property int    $merchant_id
 * @property string $name
 * @property string $url
 * @property string $hmac_secret    32-byte hex (64 chars)
 * @property array  $events         e.g. ["admin_reverse","bucket_expire"]
 * @property bool   $active
 */
class WebhookEndpoint extends Model
{
    use HasFactory;

    protected $fillable = ['merchant_id', 'name', 'url', 'hmac_secret', 'events', 'active'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->events, true);
    }
}
