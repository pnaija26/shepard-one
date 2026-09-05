<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunitySpaceMessage extends Model
{
    public const STATUS_VISIBLE = 'visible';
    public const STATUS_RESTRICTED = 'restricted';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'community_space_id',
        'sender_user_id',
        'sender_member_id',
        'message_type',
        'body',
        'attachments',
        'poll_options',
        'status',
        'is_pinned',
        'pinned_at',
        'pinned_by',
        'removed_at',
        'removed_by',
        'removal_reason',
        'is_sensitive',
        'retain_until',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'poll_options' => 'array',
            'is_pinned' => 'boolean',
            'is_sensitive' => 'boolean',
            'pinned_at' => 'datetime',
            'removed_at' => 'datetime',
            'retain_until' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(CommunitySpace::class, 'community_space_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function moderationEvents(): HasMany
    {
        return $this->hasMany(CommunitySpaceModerationEvent::class);
    }
}
