<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingSessionAttendanceCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'training_session_attendance_id',
        'corrected_by',
        'before_status',
        'after_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(TrainingSessionAttendance::class, 'training_session_attendance_id');
    }
}
