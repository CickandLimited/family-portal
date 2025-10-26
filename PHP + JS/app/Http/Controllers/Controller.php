<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Services\Progress\ProgressService;
use App\Services\XP\XPService;

abstract class Controller
{
    public function __construct(
        protected ProgressService $progressService,
        protected XPService $xpService,
        protected ActivityLogger $activityLogger,
    ) {
    }

    protected function progress(): ProgressService
    {
        return $this->progressService;
    }

    protected function xp(): XPService
    {
        return $this->xpService;
    }

    protected function activityLogger(): ActivityLogger
    {
        return $this->activityLogger;
    }
}
