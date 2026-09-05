<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportScheduleRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATING = 'generating';
    public const STATUS_DELIVERING = 'delivering';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'report_schedule_id',
        'run_key',
        'scheduled_for',
        'status',
        'report_export_id',
        'recipient_snapshot',
        'failure_reason',
        'generation_checked_at',
        'delivery_checked_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'recipient_snapshot' => 'array',
            'generation_checked_at' => 'datetime',
            'delivery_checked_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }

    public function export(): BelongsTo
    {
        return $this->belongsTo(ReportExport::class, 'report_export_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportScheduleDelivery::class);
    }
}
