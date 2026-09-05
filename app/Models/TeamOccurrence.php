<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamOccurrence extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'service_team_id',
        'branch_id',
        'occurrence_type',
        'title',
        'occurrence_date',
        'start_time',
        'end_time',
        'team_roster_id',
        'team_roster_slot_id',
        'gathering_key',
        'gathering_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(TeamRoster::class, 'team_roster_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(TeamAttendanceRecord::class);
    }
}
