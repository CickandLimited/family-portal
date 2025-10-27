<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalMood;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'approval';

    protected $fillable = [
        'subtask_id',
        'action',
        'mood',
        'reason',
        'acted_by_device_id',
        'acted_by_user_id',
    ];

    protected $casts = [
        'action' => ApprovalAction::class,
        'mood' => ApprovalMood::class,
        'created_at' => 'datetime',
    ];

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function actedByDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'acted_by_device_id');
    }

    public function actedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acted_by_user_id');
    }
}
