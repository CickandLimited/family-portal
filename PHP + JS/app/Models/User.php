<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    use HasFactory;

    protected $table = 'user';

    protected $fillable = [
        'display_name',
        'role',
        'avatar',
        'is_active',
    ];

    protected $casts = [
        'role' => UserRole::class,
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'linked_user_id');
    }

    public function assignedPlans(): HasMany
    {
        return $this->hasMany(Plan::class, 'assignee_user_id');
    }

    public function createdPlans(): HasMany
    {
        return $this->hasMany(Plan::class, 'created_by_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'acted_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'uploaded_by_user_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(SubtaskSubmission::class, 'submitted_by_user_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function xpEvents(): HasMany
    {
        return $this->hasMany(XPEvent::class);
    }
}
