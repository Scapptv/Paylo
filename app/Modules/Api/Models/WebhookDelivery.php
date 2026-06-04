<?php

declare(strict_types=1);

namespace App\Modules\Api\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbound webhook delivery attempt record.
 *
 * @property int      $id
 * @property string   $event_id            ULID — POSNET idempotency key
 * @property int      $endpoint_id
 * @property string   $event_type
 * @property array    $payload
 * @property string   $status              pending|delivered|failed
 * @property int      $attempt_count
 * @property ?\Illuminate\Support\Carbon $last_attempt_at
 * @property ?int     $last_response_status
 * @property ?string  $last_response_body
 * @property ?\Illuminate\Support\Carbon $delivered_at
 */
class WebhookDelivery extends Model
{
    use HasFactory;

    public const STATUS_PENDING   = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED    = 'failed';

    protected $fillable = [
        'event_id', 'endpoint_id', 'event_type', 'payload', 'status',
        'attempt_count', 'last_attempt_at', 'last_response_status',
        'last_response_body', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'              => 'array',
            'attempt_count'        => 'integer',
            'last_response_status' => 'integer',
            'last_attempt_at'      => 'datetime',
            'delivered_at'         => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }
}
