<?php

declare(strict_types=1);

namespace Database\Factories\Core\Models;

use App\Core\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'code'             => 'm_' . fake()->unique()->numberBetween(1000, 999999),
            'name'             => fake()->company(),
            'legal_name'       => fake()->company() . ' MMC',
            'tin'              => (string) fake()->unique()->numerify('##########'),
            'mcc'              => fake()->numberBetween(4000, 9999),
            'category'         => fake()->randomElement(['grocery', 'restaurant', 'fuel', 'pharmacy', 'retail']),
            'tier'             => 'standard',
            'status'           => 'active',
            'region'           => fake()->randomElement(['Baku', 'Ganja', 'Sumqayit', 'Mingachevir']),
            // Azerbaijani IBAN: AZ + 2 check digits + 4 alphabetic bank code + 20 alphanumeric.
            'settlement_iban'  => 'AZ' . fake()->numerify('##') . fake()->regexify('[A-Z]{4}') . fake()->numerify('####################'),
            'settlement_cycle' => 'T+3',
            'onboarded_at'     => now()->subDays(fake()->numberBetween(1, 365)),
        ];
    }

    public function active(): self
    {
        return $this->state(fn () => ['status' => 'active']);
    }

    public function paused(): self
    {
        return $this->state(fn () => ['status' => 'paused']);
    }

    public function pending(): self
    {
        return $this->state(fn () => ['status' => 'pending']);
    }
}
