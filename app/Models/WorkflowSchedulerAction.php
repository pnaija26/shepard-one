<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.3: idempotent scheduler action window (reminder/escalation).
 */
class WorkflowSchedulerAction extends Model
{
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_ESCALATION = 'escalation';

    protected $fillable = [
        'workflow_instance_id',
        'action_type',
        'window_key',
        'metadata',
        'actor_id',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }
}
