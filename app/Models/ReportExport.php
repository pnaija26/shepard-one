<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    public const TYPE_STANDARD = 'standard';
    public const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'reference',
        'requested_by',
        'report_type',
        'report_key',
        'custom_report_id',
        'format',
        'status',
        'filters',
        'metadata',
        'classification',
        'row_count',
        'storage_path',
        'download_token_hash',
        'download_expires_at',
        'completed_at',
        'failed_at',
        'failure_reason',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'metadata' => 'array',
            'download_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function customReport(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class);
    }
}
