<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\ValueObjects\BonusValue;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ƏSAS ANLAYIŞ: per-merchant bucket.
 *
 * Hər müştəri × merchant cütü üçün **bir** bucket var. Bonus yalnız öz merchant-ında xərclənir.
 * `balance` daxili olaraq integer-dir (qəpik səviyyəsi) — float xətaları olmasın deyə.
 *
 * @property int $user_id
 * @property int $merchant_id
 * @property int $balance   raw amount (1 AZN = 100)
 * @property int $earned_total
 * @property int $redeemed_total
 * @property int $expired_total
 */
class Bucket extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'merchant_id', 'balance',
        'earned_total', 'redeemed_total', 'expired_total',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'balance'          => 'integer',
            'earned_total'     => 'integer',
            'redeemed_total'   => 'integer',
            'expired_total'    => 'integer',
            'last_activity_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** @return Attribute<BonusValue, never> */
    protected function balanceValue(): Attribute
    {
        return Attribute::get(fn () => new BonusValue($this->balance));
    }

    public function canSpend(BonusValue $amount): bool
    {
        return $this->balance >= $amount->amount;
    }
}
