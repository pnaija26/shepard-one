<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 1.5: a single, centrally identified person moving between branches.
 *
 * One row = one movement request for ONE identity (person_id). The same person
 * can have many rows over time (a history of requests), but never two live
 * identities — the active branch association always lives on users.branch_id
 * and is changed in place when a movement becomes effective.
 */
class MemberMovement extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';

    protected $fillable = [
        'person_id',
        'source_branch_id',
        'destination_branch_id',
        'effective_date',
        'reason',
        'status',
        'initiated_by',
        'decided_by',
        'decided_at',
        'decision_reason',
        'applied_at',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'decided_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'person_id');
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'destination_branch_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** A movement is still actionable while pending or approved-but-not-yet-applied. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_APPROVED], true);
    }
}
