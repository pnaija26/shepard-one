<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TrainingEnrolment extends Model
{
    public const STATUS_ENROLLED = 'enrolled';
    public const STATUS_WAITLISTED = 'waitlisted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'training_offering_id',
        'training_offering_version_id',
        'member_id',
        'branch_id',
        'status',
        'waitlist_position',
        'rejection_reason',
        'schedule_snapshot',
        'materials_snapshot',
        'enrolled_by',
    ];

    protected function casts(): array
    {
        return [
            'schedule_snapshot' => 'array',
            'materials_snapshot' => 'array',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(TrainingOffering::class, 'training_offering_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TrainingOfferingVersion::class, 'training_offering_version_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function sessionAttendance(): HasMany
    {
        return $this->hasMany(TrainingSessionAttendance::class);
    }

    public function assessmentResults(): HasMany
    {
        return $this->hasMany(TrainingAssessmentResult::class);
    }

    public function completionRecord(): HasOne
    {
        return $this->hasOne(TrainingCompletionRecord::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(TrainingCertificate::class);
    }
}
