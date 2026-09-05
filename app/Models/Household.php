<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Household extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'head_member_id',
        'shared_phone',
        'shared_email',
        'shared_address',
        'milestones',
        'attendance_summary',
        'events_summary',
        'teams_summary',
        'welfare_references',
        'created_by',
    ];

    protected $casts = [
        'shared_address' => 'array',
        'milestones' => 'array',
        'attendance_summary' => 'array',
        'events_summary' => 'array',
        'teams_summary' => 'array',
        'welfare_references' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function headMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'head_member_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(HouseholdMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereNull('ended_at');
    }

    public function history(): HasMany
    {
        return $this->hasMany(HouseholdRelationshipHistory::class);
    }
}
