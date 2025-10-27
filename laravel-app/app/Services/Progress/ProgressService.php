<?php

namespace App\Services\Progress;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\PlanDay;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final class ProgressService
{
    public function __construct(private readonly ProgressCache $cache)
    {
    }

    public function calculateDayProgress(PlanDay $day, ?ProgressCache $cache = null): DayProgress
    {
        $cache ??= $this->cache;

        return $cache->dayProgress($day);
    }

    public function calculatePlanProgress(Plan $plan, ?ProgressCache $cache = null): PlanProgress
    {
        $cache ??= $this->cache;

        return $cache->planProgress($plan);
    }

    public function refreshPlanDayLocks(Plan $plan, ?ProgressCache $cache = null): bool
    {
        $cache ??= $this->cache;

        /** @var Collection<int, PlanDay> $days */
        $days = $this->orderedDays($plan);

        if ($days->isEmpty()) {
            if ($plan->status === PlanStatus::COMPLETE) {
                $plan->status = PlanStatus::IN_PROGRESS;
                $plan->updated_at = Carbon::now();

                return true;
            }

            return false;
        }

        $anyChanges = false;
        $previousComplete = true;
        $allDaysComplete = true;

        foreach ($days as $day) {
            $metrics = $cache->dayProgress($day);
            $dayComplete = $metrics->isComplete();
            $allDaysComplete = $allDaysComplete && $dayComplete;

            $shouldBeLocked = $this->isDayLocked($previousComplete);

            if ((bool) $day->locked !== $shouldBeLocked) {
                $day->locked = $shouldBeLocked;
                $day->updated_at = Carbon::now();
                $anyChanges = true;
            }

            $previousComplete = $dayComplete;
        }

        if ($allDaysComplete) {
            if ($plan->status !== PlanStatus::COMPLETE) {
                $plan->status = PlanStatus::COMPLETE;
                $plan->updated_at = Carbon::now();
                $anyChanges = true;
            }
        } elseif ($plan->status === PlanStatus::COMPLETE) {
            $plan->status = PlanStatus::IN_PROGRESS;
            $plan->updated_at = Carbon::now();
            $anyChanges = true;
        }

        return $anyChanges;
    }

    public function isDayLocked(bool $previousComplete): bool
    {
        return ! $previousComplete;
    }

    /**
     * @return Collection<int, PlanDay>
     */
    private function orderedDays(Plan $plan): Collection
    {
        if (! $plan->relationLoaded('days')) {
            $plan->load('days.subtasks');
        } else {
            /** @var Collection<int, PlanDay> $days */
            $days = $plan->getRelation('days');
            $days->load('subtasks');
        }

        /** @var Collection<int, PlanDay> $ordered */
        $ordered = $plan->getRelation('days')->sortBy('day_index')->values();

        return $ordered;
    }
}
