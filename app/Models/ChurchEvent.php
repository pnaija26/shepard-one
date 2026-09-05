<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChurchEvent extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_POSTPONED = 'postponed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';

    public const REGISTRATION_OPEN = 'open';
    public const REGISTRATION_CLOSED = 'closed';
    public const REGISTRATION_NA = 'not_applicable';

    protected $fillable = [
        'branch_id',
        'title',
        'description',
        'event_date',
        'end_date',
        'start_time',
        'end_time',
        'venue',
        'capacity',
        'speakers',
        'registration',
        'ticketing_policy',
        'volunteers',
        'materials',
        'budget',
        'reminders',
        'status',
        'registration_availability',
        'postponed_to_date',
        'published_at',
        'postponed_at',
        'cancelled_at',
        'completed_at',
        'closed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'end_date' => 'date',
            'postponed_to_date' => 'date',
            'speakers' => 'array',
            'registration' => 'array',
            'ticketing_policy' => 'array',
            'volunteers' => 'array',
            'materials' => 'array',
            'budget' => 'array',
            'reminders' => 'array',
            'published_at' => 'datetime',
            'postponed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ChurchEventChange::class);
    }

    public function changeEvents(): HasMany
    {
        return $this->hasMany(ChurchEventChangeEvent::class);
    }

    public function closeSnapshot(): HasOne
    {
        return $this->hasOne(ChurchEventCloseSnapshot::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(ChurchEventRegistration::class);
    }
}