<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalAdapterOperation extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'external_service_adapter_id',
        'correlation_id',
        'idempotency_key',
        'capability',
        'request_payload',
        'response_payload',
        'status',
        'attempt',
        'timeout_ms',
        'error_code',
        'next_retry_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'next_retry_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function adapter(): BelongsTo
    {
        return $this->belongsTo(ExternalServiceAdapter::class, 'external_service_adapter_id');
    }
}
