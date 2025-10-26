<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'timestamp' => fake()->dateTimeBetween('-2 days'),
            'device_id' => Device::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement(['created', 'updated', 'completed']),
            'entity_type' => fake()->randomElement(['Plan', 'Subtask', 'Attachment']),
            'entity_id' => fake()->numberBetween(1, 50),
            'metadata' => ['summary' => fake()->sentence()],
        ];
    }
}
