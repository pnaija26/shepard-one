<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchGroupMeetingAttendance extends Model
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_ABSENT = 'absent';
    public const STATUS_EXCUSED = 'excused';
    public const STATUS_LATE = 'late';

    protected $table = 'church_group_meeting_attendance';

    protected $fillable = [
        'church_group_meeting_id',
        'member_id',
        'visitor_id',
        'person_name',
        'status',
        'notes',
        'corrected_from_status',
        'correction_reason',
        'corrected_by',
        'corrected_at',
    ];

    protected function casts(): array
    {
        return [
            'corrected_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(ChurchGroupMeeting::class, 'church_group_meeting_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }
}
