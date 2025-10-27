<?php

namespace Database\Factories;

use App\Enums\SubtaskStatus;
use App\Models\PlanDay;
use App\Models\Subtask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subtask>
 */
class SubtaskFactory extends Factory
{
    protected $model = Subtask::class;

    public function definition(): array
    {
        return [
            'plan_day_id' => PlanDay::factory(),
            'order_index' => fake()->numberBetween(0, 5),
            'text' => fake()->sentence(),
            'xp_value' => fake()->numberBetween(5, 25),
            'status' => SubtaskStatus::PENDING,
        ];
    }

    public function status(SubtaskStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
        ]);
    }
}
