<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingOfferingVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'training_offering_id',
        'version',
        'status',
        'sessions',
        'prerequisites',
        'facilitators',
        'assessments',
        'materials',
        'completion_rules',
        'enrolment_rules',
        'published_by',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sessions' => 'array',
            'prerequisites' => 'array',
            'facilitators' => 'array',
            'assessments' => 'array',
            'materials' => 'array',
            'completion_rules' => 'array',
            'enrolment_rules' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function offering(): BelongsTo
    {
        return $this->belongsTo(TrainingOffering::class, 'training_offering_id');
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(TrainingEnrolment::class);
    }
}
