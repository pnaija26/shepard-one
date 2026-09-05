<?php

namespace App\Models;

use App\Services\WorkflowException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.3: immutable workflow instance history entry.
 */
class WorkflowInstanceEvent extends Model
{
    public const TYPE_STARTED = 'started';
    public const TYPE_TRANSITIONED = 'transitioned';
    public const TYPE_APPROVED = 'approved';
    public const TYPE_REJECTED = 'rejected';
    public const TYPE_RETURNED = 'returned';
    public const TYPE_COMPLETED = 'completed';
    public const TYPE_REASSIGNED = 'reassigned';
    public const TYPE_REMINDED = 'reminded';
    public const TYPE_ESCALATED = 'escalated';
    public const TYPE_FAILED = 'failed';

    protected $fillable = [
        'workflow_instance_id',
        'event_type',
        'decision',
        'from_state',
        'to_state',
        'comment',
        'actor_id',
        'from_assignee_id',
        'to_assignee_id',
        'metadata',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new WorkflowException('Workflow instance events cannot be modified.', 'immutable', 422);
        });

        static::deleting(function () {
            throw new WorkflowException('Workflow instance events cannot be deleted.', 'immutable', 422);
        });
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
