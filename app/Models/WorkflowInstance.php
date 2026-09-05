<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 9.2/9.3: workflow runtime instance.
 */
class WorkflowInstance extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'reference',
        'idempotency_key',
        'workflow_id',
        'workflow_version_id',
        'workflow_version',
        'branch_id',
        'trigger_type',
        'trigger_event',
        'source_type',
        'source_id',
        'assignee_id',
        'required_permission',
        'status',
        'current_state',
        'context',
        'due_at',
        'started_at',
        'completed_at',
        'escalation_count',
        'last_reminder_at',
        'last_escalated_at',
        'failure_code',
        'failure_message',
        'migrated_at',
        'migrated_from_version',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'last_escalated_at' => 'datetime',
            'migrated_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkflowInstanceEvent::class)->orderBy('recorded_at');
    }

    public function schedulerActions(): HasMany
    {
        return $this->hasMany(WorkflowSchedulerAction::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, config('workflows.instance_open_statuses', []), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
        ], true);
    }
}
