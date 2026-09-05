<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchGroupMembership extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRANSFERRED = 'transferred';
    public const STATUS_REMOVED = 'removed';

    public const ROLE_LEADER = 'leader';
    public const ROLE_CO_LEADER = 'co_leader';
    public const ROLE_MEMBER = 'member';
    public const ROLE_ASSISTANT = 'assistant';

    protected $fillable = [
        'church_group_id',
        'member_id',
        'role',
        'status',
        'effective_from',
        'effective_to',
        'join_request_id',
        'assigned_by',
        'approved_by',
        'approved_at',
        'transfer_to_group_id',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'approved_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChurchGroup::class, 'church_group_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function joinRequest(): BelongsTo
    {
        return $this->belongsTo(ChurchGroupJoinRequest::class, 'join_request_id');
    }
}
