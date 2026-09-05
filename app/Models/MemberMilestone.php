<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 10.4: wedding/membership/baptism/ordination/service date for a member.
 */
class MemberMilestone extends Model
{
    protected $fillable = [
        'member_id',
        'type',
        'occurred_on',
        'active',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
