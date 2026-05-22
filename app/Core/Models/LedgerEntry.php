<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Enums\LedgerEntryType;
use App\Core\ValueObjects\BonusValue;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IMMUTABLE LEDGER:
 *   - heç vaxt update və ya delete olunmur
 *   - hər tranzaksiya yeni bir entry kimi yazılır
 *   - reversal/refund "reverses_id" sahəsi ilə original-a istinad edir
 *
 * Bu cədvəl audit, fraud-detection və settlement üçün "həqiqətin tək mənbəyi"-dir.
 *
 * @property int               $id
 * @property string            $uid           Public, idempotent identifier
 * @property int               $user_id       müştəri (customer)
 * @property int               $merchant_id   hansı bucket-ə təsir edir
 * @property int|null          $branch_id
 * @property int|null          $cashier_id    hansı istifadəçi tranzaksiyanı yaratdı
 * @property LedgerEntryType   $type
 * @property int               $amount        raw (qəpik)
 * @property int               $balance_after bucket-də qalan balans (snapshot)
 * @property string|null       $ref           xarici ref (POS receipt no)
 * @property int|null          $reverses_id   reversal/refund hallarında orijinal entry id
 * @property array|null        $meta          əlavə kontekst
 */
class LedgerEntry extends Model
{
    use HasFactory;

    // Immutable: heç vaxt update etmə
    public $timestamps = true;

    protected $fillable = [
        'uid', 'user_id', 'merchant_id', 'branch_id', 'cashier_id',
        'type', 'amount', 'balance_after', 'ref', 'reverses_id', 'meta',
        'prev_hash', 'entry_hash',
    ];

    protected function casts(): array
    {
        return [
            'type'          => LedgerEntryType::class,
            'amount'        => 'integer',
            'balance_after' => 'integer',
            'meta'          => 'array',
        ];
    }

    /**
     * Model boot — bütün entry-ləri update və delete-dən qoru.
     * Bu, immutable ledger qaydasının kod səviyyəsində enforcement-idir.
     */
    protected static function booted(): void
    {
        static::updating(function (self $entry): bool {
            throw new \RuntimeException('Ledger entry-lər immutable-dır. Update qadağandır.');
        });

        static::deleting(function (self $entry): bool {
            throw new \RuntimeException('Ledger entry-lər immutable-dır. Delete qadağandır.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function reversesEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** @return Attribute<BonusValue, never> */
    protected function amountValue(): Attribute
    {
        return Attribute::get(fn () => new BonusValue($this->amount));
    }
}
