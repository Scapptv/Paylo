<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FCM / APNs push token-ləri.
 *
 * Hər müştəri cihazı üçün bir token saxlanılır. Token rotation olarsa,
 * köhnə token silinir və yenisi yazılır.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $token         FCM/APNs registration id (uzun string)
 * @property string      $platform      ios | android
 * @property string|null $app_version
 * @property string|null $device_model
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class PushToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'token', 'platform',
        'app_version', 'device_model', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
