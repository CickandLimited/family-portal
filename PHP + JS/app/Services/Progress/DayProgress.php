<?php

namespace App\Services\Progress;

final class DayProgress
{
    public function __construct(
        public readonly int $approvedSubtasks,
        public readonly int $totalSubtasks,
    ) {
    }

    public function percentComplete(): int
    {
        if ($this->totalSubtasks === 0) {
            return 100;
        }

        return (int) round(($this->approvedSubtasks / $this->totalSubtasks) * 100);
    }

    public function isComplete(): bool
    {
        if ($this->totalSubtasks === 0) {
            return true;
        }

        return $this->approvedSubtasks === $this->totalSubtasks;
    }
}
