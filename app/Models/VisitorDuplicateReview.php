<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorDuplicateReview extends Model
{
    public const STATUS_PENDING = 'pending_review';
    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'matched_visitor_id',
        'matched_member_id',
        'confidence',
        'match_reason',
        'submitted_payload',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'submitted_payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function matchedVisitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'matched_visitor_id');
    }

    public function matchedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'matched_member_id');
    }
}
