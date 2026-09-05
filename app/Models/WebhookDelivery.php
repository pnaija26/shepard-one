<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERING = 'delivering';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'webhook_subscription_id',
        'event_id',
        'idempotency_key',
        'event_type',
        'payload_version',
        'payload',
        'status',
        'attempt',
        'http_status',
        'response_excerpt',
        'duration_ms',
        'last_error_code',
        'next_retry_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'webhook_subscription_id');
    }
}
