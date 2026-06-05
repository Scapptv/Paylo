<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit jurnalı (roadmap Phase 3.1).
 *
 * `AuditLogger` hər hadisəni həm log channel-a, həm bu cədvələ yazır (dual-write).
 * Ledger fəlsəfəsinə uyğun olaraq IMMUTABLE: update və delete model səviyyəsində
 * qadağandır — audit izi heç vaxt dəyişdirilə bilməz.
 *
 * @property int         $id
 * @property string      $event
 * @property int|null    $actor_id
 * @property array|null  $context
 * @property string|null $ip
 * @property string|null $user_agent
 */
class AuditLog extends Model
{
    // Append-only: yalnız created_at idarə olunur (updated_at yoxdur).
    public const UPDATED_AT = null;

    protected $fillable = ['event', 'actor_id', 'context', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return [
            'context'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Immutability enforcement — update/delete qadağandır (LedgerEntry ilə eyni qayda).
     */
    protected static function booted(): void
    {
        static::updating(function (self $log): void {
            throw new \RuntimeException('Audit log-lar immutable-dır. Update qadağandır.');
        });

        static::deleting(function (self $log): void {
            throw new \RuntimeException('Audit log-lar immutable-dır. Delete qadağandır.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
