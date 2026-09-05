<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchService extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'branch_id',
        'service_type',
        'title',
        'service_date',
        'start_time',
        'end_time',
        'venue',
        'ministers',
        'teams',
        'capacity',
        'registration_required',
        'registration_capacity',
        'attendance_target',
        'livestream',
        'status',
        'published_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'ministers' => 'array',
            'teams' => 'array',
            'livestream' => 'array',
            'registration_required' => 'boolean',
            'published_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ChurchServiceChange::class);
    }

    public function changeEvents(): HasMany
    {
        return $this->hasMany(ChurchServiceChangeEvent::class);
    }
}
