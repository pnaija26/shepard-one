<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingStepRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public $timestamps = false;

    protected $fillable = [
        'enrollment_id',
        'step_key',
        'day_offset',
        'action_type',
        'scheduled_for',
        'status',
        'skip_reason',
        'failure_reason',
        'result',
        'executed_at',
        'attempts',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'executed_at' => 'datetime',
        'result' => 'array',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(OnboardingEnrollment::class, 'enrollment_id');
    }
}
