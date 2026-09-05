<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WelfareRequest extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_ASSESSMENT = 'under_assessment';
    public const STATUS_RETURNED_FOR_INFO = 'returned_for_info';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_DISBURSED = 'disbursed';
    public const STATUS_FOLLOW_UP = 'follow_up';
    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'case_number',
        'branch_id',
        'beneficiary_member_id',
        'beneficiary_name',
        'requester_member_id',
        'requester_user_id',
        'request_type',
        'description',
        'priority',
        'requested_value',
        'currency',
        'consent_data_processing',
        'consent_welfare_review',
        'status',
        'supporting_documents',
        'validation_errors',
        'is_restricted',
        'submitted_at',
        'created_by',
        'updated_by',
        'assigned_officer_id',
        'current_assessment_version',
        'beneficiary_status_message',
        'returned_at',
        'escalated_at',
        'approval_config_version_id',
        'current_approval_step',
        'approval_status',
        'approved_value',
        'disbursed_at',
        'follow_up_at',
        'follow_up_due_on',
        'closed_at',
        'closure_reason',
        'last_follow_up_reminder_at',
        'follow_up_escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_value' => 'decimal:2',
            'approved_value' => 'decimal:2',
            'consent_data_processing' => 'boolean',
            'consent_welfare_review' => 'boolean',
            'supporting_documents' => 'array',
            'validation_errors' => 'array',
            'is_restricted' => 'boolean',
            'submitted_at' => 'datetime',
            'returned_at' => 'datetime',
            'escalated_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'follow_up_at' => 'datetime',
            'follow_up_due_on' => 'date',
            'closed_at' => 'datetime',
            'last_follow_up_reminder_at' => 'datetime',
            'follow_up_escalated_at' => 'datetime',
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

    public function requesterMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requester_member_id');
    }

    public function requesterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(WelfareAssessmentVersion::class);
    }

    public function caseEvents(): HasMany
    {
        return $this->hasMany(WelfareCaseEvent::class);
    }

    public function approvalConfigVersion(): BelongsTo
    {
        return $this->belongsTo(WelfareApprovalConfigVersion::class, 'approval_config_version_id');
    }

    public function approvalSteps(): HasMany
    {
        return $this->hasMany(WelfareApprovalStep::class);
    }

    public function approvalDecisions(): HasMany
    {
        return $this->hasMany(WelfareApprovalDecision::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WelfareAssistanceDelivery::class);
    }

    public function followUpEntries(): HasMany
    {
        return $this->hasMany(WelfareFollowUpEntry::class)->orderBy('recorded_at');
    }

    public function followUpReminders(): HasMany
    {
        return $this->hasMany(WelfareFollowUpReminder::class);
    }
}
