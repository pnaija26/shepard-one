<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingSessionAttendance extends Model
{
    protected $table = 'training_session_attendance';

    protected $fillable = [
        'training_enrolment_id',
        'session_key',
        'session_title',
        'status',
        'recorded_by',
    ];

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrolment::class, 'training_enrolment_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(TrainingSessionAttendanceCorrection::class);
    }
}
