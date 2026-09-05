<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 2.1: church member profile (distinct from platform User accounts).
 */
class Member extends Model
{
    use HasFactory;

    protected $fillable = [
        'merged_into_id',
        'merged_at',
        'user_id',
        'occupation',
        'photo_path',
        'emergency_contact',
        'membership_id',
        'branch_id',
        'registration_channel',
        'first_name',
        'last_name',
        'preferred_name',
        'email',
        'phone',
        'date_of_birth',
        'gender',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'membership_status',
        'lifecycle_stage',
        'lifecycle_status',
        'lifecycle_policy',
        'consent_data_processing',
        'consent_directory',
        'directory_visibility',
        'directory_visibility_pending',
        'directory_visibility_effective_at',
        'directory_consent_at',
        'spiritual_gifts',
        'skills',
        'ministry_interests',
        'communication_preferences',
        'restricted_summaries',
        'archived_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'consent_data_processing' => 'boolean',
        'consent_directory' => 'boolean',
        'directory_visibility' => 'array',
        'directory_visibility_pending' => 'array',
        'directory_visibility_effective_at' => 'datetime',
        'directory_consent_at' => 'datetime',
        'spiritual_gifts' => 'array',
        'skills' => 'array',
        'ministry_interests' => 'array',
        'communication_preferences' => 'array',
        'emergency_contact' => 'array',
        'restricted_summaries' => 'array',
        'lifecycle_policy' => 'array',
        'archived_at' => 'datetime',
        'merged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(MemberProfileChangeRequest::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(MemberNotification::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(MemberProfileHistory::class);
    }

    public function lifecycleHistory(): HasMany
    {
        return $this->hasMany(MemberLifecycleHistory::class);
    }

    public function volunteerProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(VolunteerProfile::class);
    }

    public function isArchived(): bool
    {
        return $this->merged_into_id !== null
            || $this->archived_at !== null
            || $this->membership_status === 'archived';
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'merged_into_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
