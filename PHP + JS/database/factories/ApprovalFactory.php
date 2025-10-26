<?php

namespace Database\Factories;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalMood;
use App\Models\Approval;
use App\Models\Device;
use App\Models\Subtask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    public function definition(): array
    {
        return [
            'subtask_id' => Subtask::factory(),
            'action' => ApprovalAction::APPROVE,
            'mood' => ApprovalMood::NEUTRAL,
            'reason' => fake()->optional(0.4)->sentence(),
            'acted_by_device_id' => Device::factory(),
            'acted_by_user_id' => null,
        ];
    }

    public function denied(): static
    {
        return $this->state(fn () => [
            'action' => ApprovalAction::DENY,
        ]);
    }

    public function mood(ApprovalMood $mood): static
    {
        return $this->state(fn () => [
            'mood' => $mood,
        ]);
    }

    public function actedByUser(User $user): static
    {
        return $this->state(fn () => [
            'acted_by_user_id' => $user->id,
        ]);
    }
}
