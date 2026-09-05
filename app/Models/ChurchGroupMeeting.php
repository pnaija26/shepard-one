<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchGroupMeeting extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'church_group_id',
        'branch_id',
        'title',
        'meeting_type',
        'scheduled_at',
        'completed_at',
        'status',
        'location',
        'notes',
        'sensitive_notes',
        'prayer_needs',
        'actions',
        'report_fields',
        'report_submitted_at',
        'submitted_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'prayer_needs' => 'array',
            'actions' => 'array',
            'report_fields' => 'array',
            'report_submitted_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChurchGroup::class, 'church_group_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(ChurchGroupMeetingAttendance::class);
    }
}
