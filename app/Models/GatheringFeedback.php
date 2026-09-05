<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GatheringFeedback extends Model
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_MODERATION_HOLD = 'moderation_hold';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_REASSIGNED = 'reassigned';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'gathering_feedback';

    protected $fillable = [
        'gathering_type',
        'gathering_id',
        'branch_id',
        'category',
        'body',
        'rating',
        'identity_mode',
        'submitter_type',
        'submitter_id',
        'submitter_display_name',
        'assigned_team',
        'assignee_id',
        'status',
        'moderation_reason',
        'consent_feedback_notifications',
        'submitted_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_feedback_notifications' => 'boolean',
            'closed_at' => 'datetime',
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

    public function submitter(): MorphTo
    {
        return $this->morphTo();
    }

    public function activities(): HasMany
    {
        return $this->hasMany(GatheringFeedbackActivity::class);
    }
}
