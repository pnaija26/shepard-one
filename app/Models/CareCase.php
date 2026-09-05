<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 8.1: restricted pastoral / member-care case.
 */
class CareCase extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'case_number',
        'branch_id',
        'beneficiary_member_id',
        'category',
        'description',
        'sensitive_notes',
        'priority',
        'status',
        'consent_basis',
        'confidentiality',
        'data_classification',
        'is_restricted',
        'evidence',
        'assigned_care_role',
        'assigned_officer_id',
        'created_by',
        'updated_by',
        'assigned_at',
        'next_follow_up_on',
        'closed_at',
        'closure_reason',
        'closure_outcome',
        'future_care_plan',
        'reopened_at',
        'reopen_reason',
        'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'description' => 'encrypted',
            'sensitive_notes' => 'encrypted',
            'closure_outcome' => 'encrypted',
            'future_care_plan' => 'encrypted',
            'evidence' => 'array',
            'is_restricted' => 'boolean',
            'assigned_at' => 'datetime',
            'next_follow_up_on' => 'date',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'beneficiary_member_id');
    }

    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CareCaseActivity::class)->orderBy('recorded_at');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(CareCaseEscalation::class)->orderBy('created_at');
    }
}