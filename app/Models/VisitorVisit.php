<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorVisit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'visitor_id',
        'branch_id',
        'visit_date',
        'service_or_event',
        'attendance_status',
        'source',
        'decisions',
        'salvation_response',
        'prayer_needs',
        'membership_interest',
        'consent_data_processing',
        'consent_follow_up',
        'recorded_by',
        'created_at',
    ];

    protected $casts = [
        'visit_date' => 'date:Y-m-d',
        'decisions' => 'array',
        'membership_interest' => 'boolean',
        'consent_data_processing' => 'boolean',
        'consent_follow_up' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
