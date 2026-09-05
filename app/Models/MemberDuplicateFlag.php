<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberDuplicateFlag extends Model
{
    public const STATUS_PENDING = 'pending_review';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_MERGED = 'merged';

    protected $fillable = [
        'member_a_id',
        'member_b_id',
        'confidence',
        'match_reason',
        'match_signals',
        'source',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'match_signals' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function memberA(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_a_id');
    }

    public function memberB(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_b_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function pairIds(int $firstId, int $secondId): array
    {
        return $firstId < $secondId
            ? [$firstId, $secondId]
            : [$secondId, $firstId];
    }
}
