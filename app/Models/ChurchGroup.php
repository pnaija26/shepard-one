<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchGroup extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'branch_id',
        'name',
        'group_type',
        'description',
        'status',
        'leaders',
        'meeting_pattern',
        'capacity',
        'eligibility',
        'communication_settings',
        'reporting_settings',
        'created_by',
        'updated_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'leaders' => 'array',
            'meeting_pattern' => 'array',
            'eligibility' => 'array',
            'communication_settings' => 'array',
            'reporting_settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ChurchGroupMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereIn('status', config('church_groups.active_membership_statuses', []));
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(ChurchGroupJoinRequest::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(ChurchGroupMembershipHistory::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(ChurchGroupMeeting::class);
    }
}
