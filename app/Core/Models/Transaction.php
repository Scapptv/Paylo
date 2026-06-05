<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int                $merchant_id
 * @property int                $branch_id
 * @property int                $cashier_id
 * @property int                $user_id           müştəri
 * @property string             $receipt_no
 * @property int                $sale_amount       qəpik (məs: 5840 = 58.40 AZN)
 * @property int                $earned_amount
 * @property int                $redeemed_amount
 * @property TransactionStatus  $status            completed | refunded | reversed
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
            'status'          => TransactionStatus::class,
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

    /**
     * Bu tranzaksiyanın yazıldığı ledger entry-ləri.
     *
     * Audit C-2: `receipt_no` global unique deyil — yalnız `(merchant_id, receipt_no)`
     * cütü unikaldır. Filter olmadan iki fərqli merchant-ın eyni qəbz nömrəli
     * tranzaksiyaları cross-leak edərdi. `merchant_id` filteri scope-u qoruyur.
     *
     * MƏHDUDIYYƏT: Laravel `HasMany` composite foreign key dəstəkləmir. Eager
     * loading (`Transaction::with('ledgerEntries')`) ilə Laravel relation-ı
     * fresh instance üzərində çağırır — `$this->merchant_id` null olur və
     * scope səssizcə boş gəlir (səhv data göstərməkdən təhlükəsizdir, lakin
     * developer-i çaşdırır). Bu cür səssiz uğursuzluqdan qaçmaq üçün eager
     * çağırış halında açıq LogicException atırıq.
     *
     * Düzgün istifadə: `$tx->ledgerEntries()->get()` (lazy, explicit).
     */
    public function ledgerEntries(): HasMany
    {
        if ($this->merchant_id === null) {
            throw new \LogicException(
                'Transaction::ledgerEntries eager loading dəstəkləmir — composite '
                . 'foreign key (merchant_id, receipt_no). Lazy istifadə edin: '
                . '$tx->ledgerEntries()->get()'
            );
        }

        return $this->hasMany(LedgerEntry::class, 'ref', 'receipt_no')
            ->where('ledger_entries.merchant_id', $this->merchant_id);
    }
}
