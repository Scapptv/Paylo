<?php

declare(strict_types=1);

namespace App\Core\Models;

use App\Core\Enums\UserRole;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int                $id
 * @property string             $name
 * @property string             $email
 * @property string             $password
 * @property UserRole           $role
 * @property int|null           $merchant_id  Cashier/MerchantOwner/PosTerminal üçün məcburi
 * @property string|null        $customer_qr  Customer üçün unique scan id
 * @property bool               $is_active
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role',
        'merchant_id', 'customer_qr', 'phone', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'role'              => UserRole::class,
            'is_active'         => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** Customer rolu üçün — onun bütün per-merchant bucket-ləri */
    public function buckets(): HasMany
    {
        return $this->hasMany(Bucket::class, 'user_id');
    }

    /** Bütün ledger yazıları (kim olduğundan asılı olaraq fərqli context-də) */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'user_id');
    }

    /** Customer-in mobil cihazlarının push token-ləri */
    public function pushTokens(): HasMany
    {
        return $this->hasMany(PushToken::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function belongsToMerchant(int $merchantId): bool
    {
        return $this->merchant_id === $merchantId;
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->name ?: $this->email);
    }

    /*
    |--------------------------------------------------------------------------
    | R7 — customer_qr non-null contract for active customers
    |--------------------------------------------------------------------------
    | DB sütunu nullable qalır (admin/cashier/merchant_owner/pos_terminal üçün
    | həqiqətən null olmalıdır + ProfileController::delete anonimləşdirməsi
    | aktivlik bayrağını söndürəndə qrı təmizləyir).
    |
    | Lakin domen invariantı belədir: "rolu Customer və hesabı aktivdirsə,
    | customer_qr mütləq unique, non-empty olmalıdır." Bu invariantı kod
    | səviyyəsində model `saving` event-ində fail-safe doldururuq ki, hər
    | hansı yol (factory, manuel create, admin paneli, migration backfill)
    | təsadüfən null Customer yarada bilməsin.
    */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            $isActiveCustomer = $user->role === UserRole::Customer
                && (bool) ($user->is_active ?? true);

            if ($isActiveCustomer && empty($user->customer_qr)) {
                $user->customer_qr = self::generateUniqueCustomerQr();
            }
        });
    }

    /**
     * Unique `qr_` + 12 lowercase alphanumeric. Collision (cüzi ehtimal) halında
     * yenidən cəhd edir. DB unique index race-i ikinci müdafiə xəttidir.
     */
    public static function generateUniqueCustomerQr(): string
    {
        do {
            $candidate = 'qr_' . Str::lower(Str::random(12));
        } while (static::query()->where('customer_qr', $candidate)->exists());

        return $candidate;
    }

    /**
     * Audit C-5: pre-check `generateUniqueCustomerQr` race-i tam aradan qaldırmır —
     * `exists()` yoxlanışı ilə `INSERT` arasında paralel sorğu eyni QR-i yaza bilər.
     * Saving event içində qoruna bilmir (event INSERT-dən qabaq fire olur), ona görə
     * `save()`-i wrap edirik: UniqueConstraintViolationException-da customer_qr-i
     * sıfırlayıb saving event-in yeni QR generasiya etməsi üçün 3 dəfəyə qədər
     * təkrar cəhd edirik.
     */
    public function save(array $options = []): bool
    {
        $maxAttempts = 3;

        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::save($options);
            } catch (UniqueConstraintViolationException $e) {
                $isActiveCustomer = $this->role === UserRole::Customer
                    && (bool) ($this->is_active ?? true);

                // Yalnız: aktiv customer + QR sahə dəyəri var + maksimum cəhdə
                // çatmamışıq → retry. Digər unique constraint-lər (məs. email)
                // burada gizlədilməməlidir.
                if (! $isActiveCustomer || $this->customer_qr === null || $attempt >= $maxAttempts) {
                    throw $e;
                }

                // QR-i null-a çək — saving event yenidən fire olunduqda generasiya edəcək.
                $this->customer_qr = null;
            }
        }
    }
}
