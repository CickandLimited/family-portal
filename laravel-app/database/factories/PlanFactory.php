<?php

namespace Database\Factories;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'assignee_user_id' => User::factory(),
            'status' => PlanStatus::IN_PROGRESS,
            'created_by_user_id' => User::factory(),
            'total_xp' => 0,
        ];
    }

    public function status(PlanStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status,
        ]);
    }
}
