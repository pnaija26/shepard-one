<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalIncident extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_RETURNED = 'returned';

    protected $fillable = [
        'reference',
        'branch_id',
        'classification',
        'priority',
        'status',
        'occurred_at',
        'location',
        'description',
        'sensitive_details',
        'evidence',
        'assigned_team',
        'owner_id',
        'is_restricted',
        'requires_review',
        'closure_outcome',
        'follow_up_actions',
        'reported_by',
        'reviewed_by',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'evidence' => 'array',
            'is_restricted' => 'boolean',
            'requires_review' => 'boolean',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(OperationalIncidentActivity::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(OperationalIncidentEscalation::class);
    }
}
