<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Story 9.1: branch/department-scoped operational task.
 */
class OperationalTask extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reference',
        'branch_id',
        'department',
        'title',
        'description',
        'priority',
        'status',
        'assignee_id',
        'created_by',
        'due_date',
        'source_type',
        'source_id',
        'attachments',
        'completion_evidence',
        'started_at',
        'completed_at',
        'cancelled_at',
        'marked_overdue_at',
        'last_overdue_reminder_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'attachments' => 'array',
            'completion_evidence' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'marked_overdue_at' => 'datetime',
            'last_overdue_reminder_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(OperationalTaskTransition::class)->orderBy('recorded_at');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(OperationalTaskReminder::class)->orderBy('sent_at');
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, config('operational_tasks.terminal_statuses', []), true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, config('operational_tasks.open_statuses', []), true);
    }
}
