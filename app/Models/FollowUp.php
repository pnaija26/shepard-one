<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FollowUp extends Model
{
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUCCESSFUL = 'successful';
    public const STATUS_UNSUCCESSFUL = 'unsuccessful';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ESCALATED = 'escalated';

    protected $fillable = [
        'person_type',
        'person_id',
        'branch_id',
        'reason',
        'assignee_id',
        'due_date',
        'contact_method',
        'priority',
        'source_type',
        'source_reference_type',
        'source_reference_id',
        'status',
        'is_restricted',
        'created_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'is_restricted' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function person(): MorphTo
    {
        return $this->morphTo();
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

    public function activities(): HasMany
    {
        return $this->hasMany(FollowUpActivity::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(FollowUpEscalation::class);
    }

    public function sourceReference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_reference_type', 'source_reference_id');
    }
}
