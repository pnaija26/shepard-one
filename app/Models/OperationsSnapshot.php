<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsSnapshot extends Model
{
    protected $fillable = [
        'correlation_id',
        'component',
        'status',
        'latency_ms',
        'error_rate',
        'queue_depth',
        'failed_jobs',
        'metrics',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'error_rate' => 'float',
            'metrics' => 'array',
            'captured_at' => 'datetime',
        ];
    }
}
