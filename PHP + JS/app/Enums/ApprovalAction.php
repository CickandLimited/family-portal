<?php

namespace App\Enums;

enum ApprovalAction: string
{
    case APPROVE = 'approve';
    case DENY = 'deny';
}
