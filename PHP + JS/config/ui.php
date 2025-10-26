<?php

use App\Enums\ApprovalMood;

return [
    'status_badges' => [
        'plan' => [
            'draft' => 'bg-slate-200 text-slate-700',
            'in_progress' => 'bg-blue-100 text-blue-700',
            'complete' => 'bg-emerald-100 text-emerald-700',
            'archived' => 'bg-slate-200 text-slate-600',
        ],
        'subtask' => [
            'pending' => 'bg-slate-200 text-slate-700',
            'submitted' => 'bg-indigo-100 text-indigo-700',
            'approved' => 'bg-emerald-100 text-emerald-700',
            'denied' => 'bg-rose-100 text-rose-700',
        ],
    ],
    'approval_moods' => [
        ['value' => ApprovalMood::HAPPY->value, 'label' => 'Happy'],
        ['value' => ApprovalMood::NEUTRAL->value, 'label' => 'Neutral'],
        ['value' => ApprovalMood::SAD->value, 'label' => 'Concerned'],
    ],
    'default_approval_mood' => ApprovalMood::NEUTRAL->value,
];
