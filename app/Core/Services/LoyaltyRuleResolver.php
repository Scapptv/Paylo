<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Models\LoyaltyRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * DB-əsaslı loyalty qaydalarının config-ə körpüsü (roadmap Phase 4.2).
 *
 * İki vəzifə:
 *  1) applyOverrides() — `loyalty_rules` cədvəlindəki override-ları runtime-da
 *     `config('loyalty.*')`-a yazır (AppServiceProvider::boot çağırır). Beləliklə
 *     `EarnCalculator`/`SaleAmountComputer` (DƏYİŞMƏZ) config oxumağa davam edir,
 *     amma dəyərlər DB-dən gəlir. Kanonik integer hesablama toxunulmur.
 *  2) registry()/effective() — admin UI üçün redaktə olunan qaydaların siyahısı
 *     və cari effektiv dəyərləri (default vs DB override mənbəyi ilə).
 *
 * Override YOXDURSA config faylı default-u qalır (backward-compatible). Performans:
 * production-da 5-dəqiqəlik cache; test mühitində cache atlanır (RefreshDatabase
 * ilə hər test təmiz DB istəyir — stale cache sızması olmasın).
 */
class LoyaltyRuleResolver
{
    private const CACHE_KEY = 'loyalty.rules.overrides.v1';

    private const CACHE_TTL = 300;

    /**
     * DB override-larını config-ə tətbiq et. Defensiv: cədvəl yoxdursa və ya hər
     * hansı xəta olarsa, config default qalır — biznes (earn) sınmamalıdır.
     */
    public function applyOverrides(): void
    {
        try {
            foreach ($this->overrides() as $key => $value) {
                config(['loyalty.' . $key => (int) $value]);
            }
        } catch (\Throwable $e) {
            // Override tətbiq oluna bilmədi — config faylı default-u qüvvədə qalır.
        }
    }

    /**
     * key => value override map (DB-dən). Cədvəl yoxdursa boş.
     *
     * @return array<string, int>
     */
    private function overrides(): array
    {
        if (! Schema::hasTable('loyalty_rules')) {
            return [];
        }

        // Test mühitində cache-ə güvənmirik (RefreshDatabase DB-ni sıfırlayır,
        // cache isə proses boyu qalır — stale override başqa testlərə sızardı).
        if (app()->environment('testing')) {
            return LoyaltyRule::query()->pluck('value', 'key')->all();
        }

        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => LoyaltyRule::query()->pluck('value', 'key')->all(),
        );
    }

    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Redaktə olunan qaydaların metadata reyestri. Kateqoriya/tier açarları config-dən
     * dinamik götürülür; min/max/unit/qrup statik metadata-dır (kod-da versiyalı).
     *
     * @return array<int, array{key: string, group: string, label: string, unit: string, min: int, max: int}>
     */
    public function registry(): array
    {
        $rules = [];

        // Earn rates — kateqoriya üzrə (basis points; 10000 = 100%).
        foreach (array_keys((array) config('loyalty.earn_rates_bp', [])) as $cat) {
            $rules[] = $this->def("earn_rates_bp.{$cat}", 'Earn rates', ucfirst((string) $cat), 'bp', 0, 10000);
        }
        $rules[] = $this->def('earn_rate_default_bp', 'Earn rates', 'Default (naməlum kateqoriya)', 'bp', 0, 10000);

        // Tier multipliers (basis points; 10000 = 1.00x).
        foreach (array_keys((array) config('loyalty.tier_multipliers_bp', [])) as $tier) {
            $rules[] = $this->def("tier_multipliers_bp.{$tier}", 'Tier multipliers', ucfirst((string) $tier), 'bp', 0, 50000);
        }

        // Redemption + expiration.
        $rules[] = $this->def('redemption.max_percent_of_sale', 'Redemption', 'Maks redeem (satışın %-i)', '%', 0, 100);
        $rules[] = $this->def('redemption.min_sale_cents', 'Redemption', 'Minimum satış (qəpik)', 'qəpik', 0, 100_000_000);
        $rules[] = $this->def('expire_after_days', 'Expiration', 'Bonusun vaxtı (gün)', 'gün', 1, 3650);

        return $rules;
    }

    /**
     * Reyestr + hər qaydanın cari effektiv dəyəri (config, override tətbiq olunmuş)
     * və mənbəyi (db override / fayl default). Admin UI üçün.
     *
     * @return array<int, array<string, mixed>>
     */
    public function effective(): array
    {
        $overrides = Schema::hasTable('loyalty_rules')
            ? LoyaltyRule::query()->pluck('value', 'key')->all()
            : [];

        return array_map(function (array $r) use ($overrides) {
            $r['value']  = (int) config('loyalty.' . $r['key']);
            $r['source'] = array_key_exists($r['key'], $overrides) ? 'db' : 'default';

            return $r;
        }, $this->registry());
    }

    /** Bir açarın reyestr tərifi (validasiya üçün də istifadə olunur). */
    public function ruleFor(string $key): ?array
    {
        foreach ($this->registry() as $r) {
            if ($r['key'] === $key) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @return array{key: string, group: string, label: string, unit: string, min: int, max: int}
     */
    private function def(string $key, string $group, string $label, string $unit, int $min, int $max): array
    {
        return ['key' => $key, 'group' => $group, 'label' => $label, 'unit' => $unit, 'min' => $min, 'max' => $max];
    }
}
