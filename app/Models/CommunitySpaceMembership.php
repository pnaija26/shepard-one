<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunitySpaceMembership extends Model
{
    public const ROLE_MEMBER = 'member';
    public const ROLE_MODERATOR = 'moderator';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_MUTED = 'muted';
    public const STATUS_BANNED = 'banned';
    public const STATUS_LEFT = 'left';

    protected $fillable = [
        'community_space_id',
        'user_id',
        'member_id',
        'role',
        'status',
        'joined_at',
        'moderated_at',
        'moderated_by',
        'moderation_reason',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'moderated_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(CommunitySpace::class, 'community_space_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
