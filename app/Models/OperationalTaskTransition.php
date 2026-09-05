<?php

namespace App\Models;

use App\Services\OperationalTaskException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.1: immutable operational task status transition.
 */
class OperationalTaskTransition extends Model
{
    protected $fillable = [
        'operational_task_id',
        'from_status',
        'to_status',
        'notes',
        'completion_evidence',
        'actor_id',
        'metadata',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'completion_evidence' => 'array',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new OperationalTaskException('Task transitions cannot be modified.', 'immutable', 422);
        });

        static::deleting(function () {
            throw new OperationalTaskException('Task transitions cannot be deleted.', 'immutable', 422);
        });
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
