<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamAttendanceCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'team_attendance_record_id',
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

    public function record(): BelongsTo
    {
        return $this->belongsTo(TeamAttendanceRecord::class, 'team_attendance_record_id');
    }
}
