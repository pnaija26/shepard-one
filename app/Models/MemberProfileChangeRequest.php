<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProfileChangeRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'member_id',
        'field_name',
        'current_value',
        'proposed_value',
        'status',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'decision_reason',
        'member_notified_at',
    ];

    protected $casts = [
        'current_value' => 'array',
        'proposed_value' => 'array',
        'reviewed_at' => 'datetime',
        'member_notified_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
