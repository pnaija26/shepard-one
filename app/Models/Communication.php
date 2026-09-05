<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 10.1: permission-aware multi-channel communication send.
 */
class Communication extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'reference',
        'branch_id',
        'name',
        'subject',
        'body',
        'purpose',
        'channels',
        'audience_type',
        'audience_params',
        'schedule_type',
        'scheduled_at',
        'next_run_at',
        'recurrence_rule',
        'status',
        'sensitive_content',
        'batch_size',
        'rate_limit_per_minute',
        'queued_count',
        'sent_count',
        'skipped_count',
        'failed_count',
        'deferred_count',
        'source_type',
        'source_id',
        'created_by',
        'cancelled_by',
        'cancelled_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'audience_params' => 'array',
            'sensitive_content' => 'boolean',
            'scheduled_at' => 'datetime',
            'next_run_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(CommunicationDelivery::class);
    }
}
