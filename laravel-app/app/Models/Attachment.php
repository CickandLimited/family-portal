<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'attachment';

    protected $fillable = [
        'plan_id',
        'subtask_id',
        'file_path',
        'thumb_path',
        'uploaded_by_device_id',
        'uploaded_by_user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function uploadedByDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'uploaded_by_device_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
