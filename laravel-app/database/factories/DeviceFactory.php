<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'friendly_name' => fake()->optional()->words(2, true),
            'linked_user_id' => null,
            'last_seen_at' => fake()->optional()->dateTimeBetween('-2 days'),
        ];
    }
}
