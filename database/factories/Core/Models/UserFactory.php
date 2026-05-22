<?php

declare(strict_types=1);

namespace Database\Factories\Core\Models;

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * Default state: aktiv Customer.
     * Role-a görə merchant_id avtomatik configured() metodunda doldurulur.
     */
    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'phone'             => fake()->unique()->e164PhoneNumber(),
            'email_verified_at' => now(),
            'password'          => 'password', // 'hashed' cast onu hash edir
            'remember_token'    => Str::random(10),
            'role'              => UserRole::Customer,
            'merchant_id'       => null,
            'customer_qr'       => 'qr_' . Str::lower(Str::random(16)),
            'is_active'         => true,
        ];
    }

    public function customer(): self
    {
        return $this->state(fn () => [
            'role'        => UserRole::Customer,
            'merchant_id' => null,
        ]);
    }

    public function admin(): self
    {
        return $this->state(fn () => [
            'role'        => UserRole::Admin,
            'merchant_id' => null,
            'customer_qr' => null,
        ]);
    }

    public function merchantOwner(int $merchantId): self
    {
        return $this->state(fn () => [
            'role'        => UserRole::MerchantOwner,
            'merchant_id' => $merchantId,
            'customer_qr' => null,
        ]);
    }

    public function cashier(int $merchantId): self
    {
        return $this->state(fn () => [
            'role'        => UserRole::Cashier,
            'merchant_id' => $merchantId,
            'customer_qr' => null,
        ]);
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
