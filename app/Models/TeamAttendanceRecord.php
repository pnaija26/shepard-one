<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamAttendanceRecord extends Model
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_EXCUSED = 'excused';
    public const STATUS_LATE = 'late';

    protected $fillable = [
        'team_occurrence_id',
        'member_id',
        'team_roster_slot_id',
        'status',
        'captured_at',
        'recorded_by',
        'corrected_at',
        'original_status',
        'correction_reason',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(TeamOccurrence::class, 'team_occurrence_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(TeamAttendanceCorrection::class);
    }
}
