<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanDay>
 */
class PlanDayFactory extends Factory
{
    protected $model = PlanDay::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'day_index' => fake()->numberBetween(0, 6),
            'title' => 'Day '.fake()->numberBetween(1, 7),
            'locked' => fake()->boolean(20),
        ];
    }
}
