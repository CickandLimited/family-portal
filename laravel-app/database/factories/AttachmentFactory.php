<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Subtask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'subtask_id' => null,
            'file_path' => fake()->lexify('uploads/????????.jpg'),
            'thumb_path' => fake()->lexify('uploads/thumbs/????????.jpg'),
            'uploaded_by_device_id' => Device::factory(),
            'uploaded_by_user_id' => null,
        ];
    }

    public function forSubtask(Subtask $subtask): static
    {
        return $this->state(fn () => [
            'subtask_id' => $subtask->id,
            'plan_id' => $subtask->plan_day?->plan_id,
        ]);
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(fn () => [
            'uploaded_by_user_id' => $user->id,
        ]);
    }
}
