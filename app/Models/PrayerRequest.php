<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 8.3: prayer request with confidentiality scope.
 */
class PrayerRequest extends Model
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_IN_PRAYER = 'in_prayer';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_WITHDRAWN = 'withdrawn';

    public const SCOPE_PRIVATE = 'private';
    public const SCOPE_PASTOR_ONLY = 'pastor_only';
    public const SCOPE_PRAYER_TEAM = 'prayer_team';
    public const SCOPE_GROUP = 'group';
    public const SCOPE_PUBLIC_TESTIMONY = 'public_testimony';

    protected $fillable = [
        'reference',
        'branch_id',
        'requester_member_id',
        'requester_user_id',
        'submitted_by_user_id',
        'assisted_submission',
        'category',
        'priority',
        'request_body',
        'confidentiality',
        'previous_confidentiality',
        'church_group_id',
        'consent_prayer_processing',
        'consent_sharing',
        'status',
        'data_classification',
        'is_restricted',
        'submitted_at',
        'confidentiality_changed_at',
        'propagation_completed_at',
        'withdrawn_at',
        'withdrawal_reason',
        'assigned_officer_id',
        'assigned_at',
        'acknowledged_at',
        'answered_at',
        'closed_at',
        'escalated_at',
        'process_notes',
        'published_to_group',
        'published_to_group_at',
    ];

    protected function casts(): array
    {
        return [
            'request_body' => 'encrypted',
            'process_notes' => 'encrypted',
            'assisted_submission' => 'boolean',
            'consent_prayer_processing' => 'boolean',
            'consent_sharing' => 'boolean',
            'published_to_group' => 'boolean',
            'is_restricted' => 'boolean',
            'submitted_at' => 'datetime',
            'confidentiality_changed_at' => 'datetime',
            'propagation_completed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'assigned_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'answered_at' => 'datetime',
            'closed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'published_to_group_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'requester_member_id');
    }

    public function churchGroup(): BelongsTo
    {
        return $this->belongsTo(ChurchGroup::class, 'church_group_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function confidentialityEvents(): HasMany
    {
        return $this->hasMany(PrayerRequestConfidentialityEvent::class)->orderBy('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(PrayerRequestActivity::class)->orderBy('recorded_at');
    }

    public function assignedOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN || $this->withdrawn_at !== null;
    }
}
