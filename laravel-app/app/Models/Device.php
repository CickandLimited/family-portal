<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $table = 'device';

    public const UPDATED_AT = null;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'friendly_name',
        'linked_user_id',
        'last_seen_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SubtaskSubmission::class, 'submitted_by_device_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'acted_by_device_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'uploaded_by_device_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'device_id');
    }
}
