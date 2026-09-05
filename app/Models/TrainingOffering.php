<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingOffering extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'branch_id',
        'name',
        'course_type',
        'description',
        'status',
        'capacity',
        'waitlist_enabled',
        'current_version',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'waitlist_enabled' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TrainingOfferingVersion::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(TrainingEnrolment::class);
    }
}
