<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunitySpace extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'reference',
        'name',
        'space_type',
        'branch_id',
        'status',
        'retention_days',
        'requires_consent',
        'description',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'requires_consent' => 'boolean',
            'retention_days' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CommunitySpaceMembership::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunitySpaceMessage::class);
    }

    public function moderationEvents(): HasMany
    {
        return $this->hasMany(CommunitySpaceModerationEvent::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(CommunitySpaceIntegration::class);
    }
}
