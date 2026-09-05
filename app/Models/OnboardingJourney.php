<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingJourney extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'name',
        'trigger_event',
        'branch_id',
        'status',
        'current_version',
        'created_by',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(OnboardingJourneyVersion::class, 'journey_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(OnboardingEnrollment::class, 'journey_id');
    }

    public function latestVersion(): ?OnboardingJourneyVersion
    {
        return $this->versions()->orderByDesc('version')->first();
    }
}
