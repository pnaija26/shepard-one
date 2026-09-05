<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingAssessmentCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'training_assessment_result_id',
        'corrected_by',
        'before_status',
        'after_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(TrainingAssessmentResult::class, 'training_assessment_result_id');
    }
}
