<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'branch_id',
        'service_type',
        'gathering_date',
        'status',
        'team_id',
        'session_type',
        'session_id',
        'capture_method',
        'captured_at',
        'device_id',
        'sync_status',
        'client_reference',
        'service_cancelled',
        'branch_transfer',
        'recorded_by',
        'corrected_at',
        'original_status',
        'correction_reason',
    ];

    protected function casts(): array
    {
        return [
            'gathering_date' => 'date',
            'captured_at' => 'datetime',
            'service_cancelled' => 'boolean',
            'branch_transfer' => 'boolean',
            'corrected_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceRecordCorrection::class);
    }
}
