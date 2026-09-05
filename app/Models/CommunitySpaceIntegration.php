<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunitySpaceIntegration extends Model
{
    protected $fillable = [
        'community_space_id',
        'provider',
        'enabled',
        'consent_documented',
        'identity_mapping',
        'moderation_boundary',
        'config',
        'configured_by',
        'configured_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'consent_documented' => 'boolean',
            'identity_mapping' => 'array',
            'config' => 'array',
            'configured_at' => 'datetime',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(CommunitySpace::class, 'community_space_id');
    }
}
