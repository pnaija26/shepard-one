<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunitySpaceModerationEvent extends Model
{
    protected $fillable = [
        'community_space_id',
        'community_space_message_id',
        'target_user_id',
        'actor_user_id',
        'action',
        'reason',
        'details',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(CommunitySpace::class, 'community_space_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunitySpaceMessage::class, 'community_space_message_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
