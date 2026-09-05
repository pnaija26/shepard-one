<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnboardingJourneyVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'journey_id',
        'version',
        'steps',
        'stop_conditions',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'stop_conditions' => 'array',
        'published_at' => 'datetime',
    ];

    public function journey(): BelongsTo
    {
        return $this->belongsTo(OnboardingJourney::class, 'journey_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(OnboardingEnrollment::class, 'journey_version_id');
    }
}
