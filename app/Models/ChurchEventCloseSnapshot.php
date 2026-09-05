<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurchEventCloseSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'church_event_id',
        'registrations_count',
        'attendance_count',
        'volunteer_participation',
        'feedback_count',
        'incidents_count',
        'budget_summary',
        'closed_by',
        'snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'volunteer_participation' => 'array',
            'budget_summary' => 'array',
            'snapshot_at' => 'datetime',
        ];
    }

    public function churchEvent(): BelongsTo
    {
        return $this->belongsTo(ChurchEvent::class);
    }
}