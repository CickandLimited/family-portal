<?php

namespace Database\Factories;

use App\Models\Subtask;
use App\Models\User;
use App\Models\XPEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<XPEvent>
 */
class XPEventFactory extends Factory
{
    protected $model = XPEvent::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subtask_id' => Subtask::factory(),
            'delta' => fake()->numberBetween(5, 50),
            'reason' => fake()->sentence(4),
        ];
    }
}
