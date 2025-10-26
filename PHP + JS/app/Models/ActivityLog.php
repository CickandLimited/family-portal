<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'activity_log';

    protected $fillable = [
        'timestamp',
        'device_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'metadata',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
        'metadata' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
