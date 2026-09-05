<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingAssessmentResult extends Model
{
    protected $fillable = [
        'training_enrolment_id',
        'assessment_key',
        'assessment_title',
        'result_status',
        'score',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
        ];
    }

    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(TrainingEnrolment::class, 'training_enrolment_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(TrainingAssessmentCorrection::class);
    }
}
