<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupRun extends Model
{
    public const TYPE_INCREMENTAL = 'incremental';
    public const TYPE_FULL = 'full';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STALE = 'stale';

    protected $fillable = [
        'reference',
        'run_type',
        'status',
        'encrypted',
        'replicated_offsite',
        'integrity_check',
        'size_bytes',
        'duration_ms',
        'started_at',
        'completed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'encrypted' => 'boolean',
            'replicated_offsite' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
