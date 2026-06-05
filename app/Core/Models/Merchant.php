<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Enums\MerchantStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int             $id
 * @property string          $code           Public id, məs: "m_412"
 * @property string          $name
 * @property string          $legal_name
 * @property string          $tin
 * @property int             $mcc
 * @property string          $category       grocery, restaurant, fuel, pharmacy, retail ...
 * @property string          $tier           standard | premium | enterprise
 * @property MerchantStatus  $status         active | pending | paused | revoked
 * @property string          $region
 * @property string          $settlement_iban
 * @property string          $settlement_cycle  T+1, T+3, T+5 ...
 */
class Merchant extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'legal_name', 'tin', 'mcc', 'category', 'tier',
        'status', 'region', 'settlement_iban', 'settlement_cycle', 'onboarded_at',
    ];

    protected function casts(): array
    {
        return [
            'mcc'          => 'integer',
            'status'       => MerchantStatus::class,
            'onboarded_at' => 'datetime',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function buckets(): HasMany
    {
        return $this->hasMany(Bucket::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function isActive(): bool
    {
        return $this->status === MerchantStatus::Active;
    }
}
