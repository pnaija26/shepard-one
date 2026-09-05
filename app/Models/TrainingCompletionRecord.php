<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TrainingCompletionRecord extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_READY = 'ready';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'training_enrolment_id',
        'status',
        'progress_percent',
        'unmet_criteria',
        'confirmed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'decimal:2',
            'unmet_criteria' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrolment::class, 'training_enrolment_id');
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(TrainingCertificate::class, 'training_enrolment_id', 'training_enrolment_id');
    }
}
