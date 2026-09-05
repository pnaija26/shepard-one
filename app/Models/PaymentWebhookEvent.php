<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REPLAYED = 'replayed';
    public const STATUS_CONFLICT = 'conflict';

    protected $fillable = [
        'payment_source_id',
        'provider',
        'provider_event_id',
        'payment_reference',
        'status',
        'reject_reason',
        'amount_cents',
        'currency',
        'payload_sanitized',
        'signature_valid',
        'occurred_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_sanitized' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'amount_cents' => 'integer',
        ];
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }
}
