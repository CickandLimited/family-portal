<?php

namespace App\Services\Progress;

final class PlanProgress
{
    public function __construct(
        public readonly int $approvedSubtasks,
        public readonly int $totalSubtasks,
        public readonly int $completedDays,
        public readonly int $totalDays,
    ) {
    }

    public function percentComplete(): int
    {
        if ($this->totalSubtasks === 0) {
            return 0;
        }

        return (int) round(($this->approvedSubtasks / $this->totalSubtasks) * 100);
    }

    public function dayPercentComplete(): int
    {
        if ($this->totalDays === 0) {
            return 0;
        }

        return (int) round(($this->completedDays / $this->totalDays) * 100);
    }

    public function isComplete(): bool
    {
        if ($this->totalDays === 0) {
            return false;
        }

        return $this->completedDays === $this->totalDays;
    }
}
