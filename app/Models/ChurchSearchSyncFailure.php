<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Story 14.3: retryable global search indexing failure.
 */
class ChurchSearchSyncFailure extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RESOLVED = 'resolved';

    public const OPERATION_UPSERT = 'upsert';
    public const OPERATION_DELETE = 'delete';

    protected $fillable = [
        'record_type',
        'record_id',
        'operation',
        'error_message',
        'attempts',
        'status',
        'next_retry_at',
        'last_attempted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'next_retry_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
