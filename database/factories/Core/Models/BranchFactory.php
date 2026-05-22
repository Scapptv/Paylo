<?php

declare(strict_types=1);

namespace Database\Factories\Core\Models;

use App\Core\Models\Branch;
use App\Core\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'merchant_id'     => Merchant::factory(),
            'code'            => 'br_' . fake()->unique()->numberBetween(100, 999999),
            'name'            => fake()->city() . ' Branch',
            'address'         => fake()->streetAddress(),
            'pos_terminal_id' => 'pos_' . fake()->unique()->numerify('######'),
        ];
    }

    public function forMerchant(Merchant|int $merchant): self
    {
        $id = $merchant instanceof Merchant ? $merchant->id : $merchant;

        return $this->state(fn () => ['merchant_id' => $id]);
    }
}
