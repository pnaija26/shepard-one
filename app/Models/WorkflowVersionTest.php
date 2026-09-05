<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.2: retained sample-data test evidence for a workflow version.
 */
class WorkflowVersionTest extends Model
{
    protected $fillable = [
        'workflow_version_id',
        'sample_payload',
        'result',
        'passed',
        'ran_by',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'sample_payload' => 'array',
            'result' => 'array',
            'passed' => 'boolean',
            'ran_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ran_by');
    }
}
