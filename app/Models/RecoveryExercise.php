<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecoveryExercise extends Model
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'reference',
        'exercise_type',
        'status',
        'started_at',
        'completed_at',
        'measured_rpo_minutes',
        'measured_rto_minutes',
        'rpo_met',
        'rto_met',
        'verification_evidence',
        'findings',
        'corrective_actions',
        'conducted_by',
    ];

    protected function casts(): array
    {
        return [
            'rpo_met' => 'boolean',
            'rto_met' => 'boolean',
            'verification_evidence' => 'array',
            'findings' => 'array',
            'corrective_actions' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function conductor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }
}
