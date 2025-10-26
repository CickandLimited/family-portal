<?php

namespace App\Services\XP;

final class XPProgress
{
    public function __construct(
        public readonly int $level,
        public readonly int $xpIntoLevel,
        public readonly int $xpToNextLevel,
        public readonly int $progressPercent,
    ) {
    }
}
