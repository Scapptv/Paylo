<?php

declare(strict_types=1);

namespace App\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Admin-redaktə olunan loyalty qayda override-ı (roadmap Phase 4.2).
 *
 * `key` config/loyalty.php-in alt-yoludur (məs. `earn_rates_bp.grocery`,
 * `tier_multipliers_bp.premium`, `redemption.max_percent_of_sale`), `value`
 * integer dəyərdir. `LoyaltyRuleResolver` bunları config-ə tətbiq edir.
 *
 * @property int      $id
 * @property string   $key
 * @property int      $value
 * @property int|null $updated_by
 */
class LoyaltyRule extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'integer'];
    }
}
