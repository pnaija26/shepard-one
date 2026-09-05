<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationsAlert extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'reference',
        'dedup_key',
        'component',
        'metric',
        'severity',
        'status',
        'message',
        'context',
        'runbook_key',
        'correlation_id',
        'triggered_at',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'time_to_acknowledge_minutes',
        'time_to_resolve_minutes',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'triggered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
