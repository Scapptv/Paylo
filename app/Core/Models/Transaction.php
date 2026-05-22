<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $merchant_id
 * @property int    $branch_id
 * @property int    $cashier_id
 * @property int    $user_id           müştəri
 * @property string $receipt_no
 * @property int    $sale_amount       qəpik (məs: 5840 = 58.40 AZN)
 * @property int    $earned_amount
 * @property int    $redeemed_amount
 * @property string $status            completed | refunded | reversed
 */
class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id', 'branch_id', 'cashier_id', 'user_id',
        'receipt_no', 'sale_amount', 'earned_amount', 'redeemed_amount',
        'status', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'sale_amount'     => 'integer',
            'earned_amount'   => 'integer',
            'redeemed_amount' => 'integer',
            'occurred_at'     => 'datetime',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ref', 'receipt_no');
    }
}
