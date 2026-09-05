<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.1: overdue reminder record used for deduplication.
 */
class OperationalTaskReminder extends Model
{
    public const TYPE_OVERDUE = 'overdue';

    protected $fillable = [
        'operational_task_id',
        'reminder_type',
        'sent_at',
        'actor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OperationalTask::class, 'operational_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
