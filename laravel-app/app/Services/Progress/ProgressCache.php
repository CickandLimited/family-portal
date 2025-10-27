<?php

namespace App\Services\Progress;

use App\Enums\SubtaskStatus;
use App\Models\Plan;
use App\Models\PlanDay;
use Illuminate\Database\Eloquent\Collection;

final class ProgressCache
{
    /** @var array<int, DayProgress> */
    private array $dayCache = [];

    /** @var array<int, PlanProgress> */
    private array $planCache = [];

    public function dayProgress(PlanDay $day): DayProgress
    {
        $key = spl_object_id($day);

        if (! isset($this->dayCache[$key])) {
            $this->dayCache[$key] = $this->calculateDayProgress($day);
        }

        return $this->dayCache[$key];
    }

    public function planProgress(Plan $plan): PlanProgress
    {
        $key = spl_object_id($plan);

        if (! isset($this->planCache[$key])) {
            $this->planCache[$key] = $this->calculatePlanProgress($plan);
        }

        return $this->planCache[$key];
    }

    private function calculateDayProgress(PlanDay $day): DayProgress
    {
        $this->ensureDayRelationships($day);

        $approved = 0;
        $total = $day->subtasks->count();

        foreach ($day->subtasks as $subtask) {
            if ($subtask->status === SubtaskStatus::APPROVED) {
                $approved++;
            }
        }

        return new DayProgress($approved, $total);
    }

    private function calculatePlanProgress(Plan $plan): PlanProgress
    {
        $approvedSubtasks = 0;
        $totalSubtasks = 0;
        $completedDays = 0;
        $totalDays = 0;

        /** @var Collection<int, PlanDay> $days */
        $days = $this->orderedDays($plan);

        foreach ($days as $day) {
            $totalDays++;
            $metrics = $this->dayProgress($day);
            $approvedSubtasks += $metrics->approvedSubtasks;
            $totalSubtasks += $metrics->totalSubtasks;

            if ($metrics->isComplete()) {
                $completedDays++;
            }
        }

        return new PlanProgress(
            approvedSubtasks: $approvedSubtasks,
            totalSubtasks: $totalSubtasks,
            completedDays: $completedDays,
            totalDays: $totalDays,
        );
    }

    private function orderedDays(Plan $plan): Collection
    {
        $this->ensurePlanRelationships($plan);

        /** @var Collection<int, PlanDay> $days */
        $days = $plan->getRelation('days')->sortBy('day_index')->values();

        return $days;
    }

    private function ensureDayRelationships(PlanDay $day): void
    {
        if (! $day->relationLoaded('subtasks')) {
            $day->load('subtasks');
        }
    }

    private function ensurePlanRelationships(Plan $plan): void
    {
        if ($plan->relationLoaded('days')) {
            /** @var Collection<int, PlanDay> $days */
            $days = $plan->getRelation('days');
            $days->load('subtasks');

            return;
        }

        $plan->load('days.subtasks');
    }
}
