<?php

declare(strict_types=1);

namespace Database\Factories\Core\Models;

use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bucket>
 */
class BucketFactory extends Factory
{
    protected $model = Bucket::class;

    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'merchant_id'      => Merchant::factory(),
            'balance'          => 0,
            'earned_total'     => 0,
            'redeemed_total'   => 0,
            'expired_total'    => 0,
            'last_activity_at' => null,
        ];
    }

    public function for(User|int $user, Merchant|int $merchant): self
    {
        return $this->state(fn () => [
            'user_id'     => $user instanceof User ? $user->id : $user,
            'merchant_id' => $merchant instanceof Merchant ? $merchant->id : $merchant,
        ]);
    }

    public function withBalance(int $balance): self
    {
        return $this->state(fn () => [
            'balance'          => $balance,
            'earned_total'     => max($balance, 0),
            'last_activity_at' => now(),
        ]);
    }
}
