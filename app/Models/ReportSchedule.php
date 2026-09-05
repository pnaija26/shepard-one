<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportSchedule extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'reference',
        'name',
        'owner_id',
        'branch_id',
        'report_type',
        'report_key',
        'custom_report_id',
        'format',
        'delivery_channel',
        'timezone',
        'recurrence',
        'recurrence_params',
        'filters',
        'classification',
        'recipient_user_ids',
        'status',
        'next_run_at',
        'last_run_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'recurrence_params' => 'array',
            'filters' => 'array',
            'recipient_user_ids' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function customReport(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportScheduleRun::class);
    }
}
