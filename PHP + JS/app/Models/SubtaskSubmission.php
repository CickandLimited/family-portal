<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubtaskSubmission extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'subtask_submission';

    protected $fillable = [
        'subtask_id',
        'submitted_by_device_id',
        'submitted_by_user_id',
        'photo_path',
        'comment',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function submittedByDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'submitted_by_device_id');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
