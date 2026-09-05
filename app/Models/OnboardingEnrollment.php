<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OnboardingEnrollment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STOPPED = 'stopped';

    public $timestamps = false;

    protected $fillable = [
        'journey_id',
        'journey_version_id',
        'journey_version',
        'subject_type',
        'subject_id',
        'branch_id',
        'status',
        'stop_reason',
        'enrolled_at',
        'stopped_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    public function journey(): BelongsTo
    {
        return $this->belongsTo(OnboardingJourney::class, 'journey_id');
    }

    public function journeyVersion(): BelongsTo
    {
        return $this->belongsTo(OnboardingJourneyVersion::class, 'journey_version_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function stepRuns(): HasMany
    {
        return $this->hasMany(OnboardingStepRun::class, 'enrollment_id');
    }
}
