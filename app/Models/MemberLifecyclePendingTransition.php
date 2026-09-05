<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLifecyclePendingTransition extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'member_id',
        'to_stage',
        'to_status',
        'effective_date',
        'reason',
        'milestone',
        'evidence',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'decision_reason',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'milestone' => 'array',
        'evidence' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
