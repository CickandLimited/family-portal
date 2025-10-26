<?php

namespace App\Services\XP;

use App\Enums\SubtaskStatus;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\XPEvent;
use Illuminate\Database\Eloquent\Collection;

final class XPService
{
    public const XP_PER_LEVEL = 100;
    public const DAY_COMPLETION_BONUS = 20;
    public const PLAN_COMPLETION_BONUS = 50;

    public const XP_APPROVAL_REASON = 'subtask.approved';
    public const XP_DAY_COMPLETION_REASON = 'plan_day.completed';
    public const XP_PLAN_COMPLETION_REASON = 'plan.completed';

    /** @var array<string, string> */
    private const XP_REASON_LABELS = [
        self::XP_APPROVAL_REASON => 'Subtask approved',
        self::XP_DAY_COMPLETION_REASON => 'Day completion bonus',
        self::XP_PLAN_COMPLETION_REASON => 'Plan completion bonus',
    ];

    public function calculateLevel(int $totalXp, int $xpPerLevel = self::XP_PER_LEVEL): int
    {
        if ($totalXp <= 0) {
            return 0;
        }

        return intdiv($totalXp, max(1, $xpPerLevel));
    }

    public function progressForTotalXp(int $totalXp, int $xpPerLevel = self::XP_PER_LEVEL): XPProgress
    {
        $totalXp = max(0, $totalXp);
        $xpPerLevel = max(1, $xpPerLevel);
        $level = $this->calculateLevel($totalXp, $xpPerLevel);
        $xpIntoLevel = $totalXp - ($level * $xpPerLevel);
        $xpToNextLevel = $xpIntoLevel < $xpPerLevel ? $xpPerLevel - $xpIntoLevel : 0;
        $progressPercent = $xpIntoLevel <= 0
            ? 0
            : min(100, (int) round(($xpIntoLevel / $xpPerLevel) * 100));

        return new XPProgress(
            level: $level,
            xpIntoLevel: $xpIntoLevel,
            xpToNextLevel: $xpToNextLevel,
            progressPercent: $progressPercent,
        );
    }

    /**
     * @param iterable<XPEvent> $events
     */
    public function calculateUserTotalXp(iterable $events): int
    {
        $total = 0;

        foreach ($events as $event) {
            $total += (int) $event->delta;
        }

        return $total;
    }

    public function isDayComplete(PlanDay $day): bool
    {
        $this->ensureDaySubtasks($day);

        if ($day->subtasks->isEmpty()) {
            return true;
        }

        foreach ($day->subtasks as $subtask) {
            if ($subtask->status !== SubtaskStatus::APPROVED) {
                return false;
            }
        }

        return true;
    }

    public function isDayBonusEligible(PlanDay $day): bool
    {
        $this->ensureDaySubtasks($day);

        return $day->subtasks->isNotEmpty() && $this->isDayComplete($day);
    }

    public function isPlanComplete(Plan $plan): bool
    {
        $this->ensurePlanRelationships($plan);

        $anySubtasks = false;

        foreach ($plan->getRelation('days') as $day) {
            if ($day->subtasks->isNotEmpty()) {
                $anySubtasks = true;
            }

            if (! $this->isDayComplete($day)) {
                return false;
            }
        }

        return $anySubtasks;
    }

    public function daySubtaskXp(PlanDay $day): int
    {
        $this->ensureDaySubtasks($day);

        $total = 0;

        foreach ($day->subtasks as $subtask) {
            if ($subtask->status === SubtaskStatus::APPROVED) {
                $total += (int) $subtask->xp_value;
            }
        }

        return $total;
    }

    public function calculateDayTotalXp(PlanDay $day, int $bonus = self::DAY_COMPLETION_BONUS): int
    {
        $total = $this->daySubtaskXp($day);

        if ($total > 0 && $this->isDayBonusEligible($day)) {
            $total += $bonus;
        }

        return $total;
    }

    public function calculatePlanTotalXp(
        Plan $plan,
        int $dayBonus = self::DAY_COMPLETION_BONUS,
        int $planBonus = self::PLAN_COMPLETION_BONUS
    ): int {
        $total = 0;

        $this->ensurePlanRelationships($plan);

        foreach ($plan->getRelation('days') as $day) {
            $total += $this->calculateDayTotalXp($day, $dayBonus);
        }

        if ($total > 0 && $this->isPlanComplete($plan)) {
            $total += $planBonus;
        }

        return $total;
    }

    public function calculatePlanBlueprintTotalXp(
        Plan $plan,
        int $dayBonus = self::DAY_COMPLETION_BONUS,
        int $planBonus = self::PLAN_COMPLETION_BONUS
    ): int {
        $this->ensurePlanRelationships($plan);

        $base = 0;
        $dayBonuses = 0;

        foreach ($plan->getRelation('days') as $day) {
            $dayHasSubtasks = $day->subtasks->isNotEmpty();

            foreach ($day->subtasks as $subtask) {
                $base += (int) $subtask->xp_value;
            }

            if ($dayHasSubtasks) {
                $dayBonuses += $dayBonus;
            }
        }

        $planBonusValue = $plan->getRelation('days')->contains(
            static fn ($day) => $day->subtasks->isNotEmpty()
        ) ? $planBonus : 0;

        return $base + $dayBonuses + $planBonusValue;
    }

    public function reasonLabel(string $reason): string
    {
        if (isset(self::XP_REASON_LABELS[$reason])) {
            return self::XP_REASON_LABELS[$reason];
        }

        $normalized = str_replace(['_', '.'], ' ', strtolower($reason));

        return ucwords($normalized);
    }

    private function ensureDaySubtasks(PlanDay $day): void
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
