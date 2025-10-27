<?php

namespace App\Enums;

enum SubtaskStatus: string
{
    case PENDING = 'pending';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case DENIED = 'denied';
}
