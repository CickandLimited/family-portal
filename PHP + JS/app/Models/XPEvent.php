<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class XPEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'xp_event';

    protected $fillable = [
        'user_id',
        'subtask_id',
        'delta',
        'reason',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }
}
