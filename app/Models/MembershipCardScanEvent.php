<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipCardScanEvent extends Model
{
    public $timestamps = false;

    public const OUTCOME_VERIFIED = 'verified';
    public const OUTCOME_REJECTED = 'rejected';

    protected $fillable = [
        'jti',
        'member_id',
        'scanned_by',
        'purpose',
        'outcome',
        'scanned_at',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }
}
