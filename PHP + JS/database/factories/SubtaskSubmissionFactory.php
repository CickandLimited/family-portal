<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Subtask;
use App\Models\SubtaskSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubtaskSubmission>
 */
class SubtaskSubmissionFactory extends Factory
{
    protected $model = SubtaskSubmission::class;

    public function definition(): array
    {
        return [
            'subtask_id' => Subtask::factory(),
            'submitted_by_device_id' => Device::factory(),
            'submitted_by_user_id' => null,
            'photo_path' => fake()->optional(0.6)->imageUrl(640, 480, 'people'),
            'comment' => fake()->optional()->sentence(),
        ];
    }

    public function byUser(User $user): static
    {
        return $this->state(fn () => [
            'submitted_by_user_id' => $user->id,
        ]);
    }
}
