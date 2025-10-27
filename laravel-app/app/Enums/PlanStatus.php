<?php

namespace App\Enums;

enum PlanStatus: string
{
    case DRAFT = 'draft';
    case IN_PROGRESS = 'in_progress';
    case COMPLETE = 'complete';
    case ARCHIVED = 'archived';
}
