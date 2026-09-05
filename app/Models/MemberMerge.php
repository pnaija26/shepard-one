<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberMerge extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'survivor_id',
        'merged_member_id',
        'retired_membership_id',
        'field_resolutions',
        'merged_by',
        'created_at',
    ];

    protected $casts = [
        'field_resolutions' => 'array',
        'created_at' => 'datetime',
    ];

    public function survivor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'survivor_id');
    }

    public function mergedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'merged_member_id');
    }

    public function merger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
