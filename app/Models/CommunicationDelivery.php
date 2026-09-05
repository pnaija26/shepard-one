<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 10.1: per-recipient channel delivery attempt.
 */
class CommunicationDelivery extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_DEFERRED = 'deferred';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RETRIED = 'retried';

    protected $fillable = [
        'communication_id',
        'message_template_version_id',
        'member_id',
        'channel',
        'destination',
        'status',
        'skip_reason',
        'attempt',
        'provider_ref',
        'result',
        'queued_at',
        'sent_at',
        'deferred_until',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'deferred_until' => 'datetime',
        ];
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(MessageTemplateVersion::class, 'message_template_version_id');
    }
}
