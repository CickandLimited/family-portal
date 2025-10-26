<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'role' => UserRole::USER,
            'avatar' => fake()->boolean(60) ? fake()->imageUrl(300, 300, 'people') : null,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::ADMIN,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
