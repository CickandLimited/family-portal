<?php

namespace App\Models;

use App\Enums\SubtaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subtask extends Model
{
    use HasFactory;

    protected $table = 'subtask';

    protected $fillable = [
        'plan_day_id',
        'order_index',
        'text',
        'xp_value',
        'status',
    ];

    protected $casts = [
        'status' => SubtaskStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function planDay(): BelongsTo
    {
        return $this->belongsTo(PlanDay::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SubtaskSubmission::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function xpEvents(): HasMany
    {
        return $this->hasMany(XPEvent::class);
    }
}
