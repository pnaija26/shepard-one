<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VolunteerProfile extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'member_id',
        'branch_id',
        'skills',
        'expertise',
        'availability',
        'preferences',
        'experience',
        'certifications',
        'training',
        'service_history',
        'volunteer_hours',
        'restricted_notes',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'expertise' => 'array',
            'availability' => 'array',
            'preferences' => 'array',
            'experience' => 'array',
            'certifications' => 'array',
            'training' => 'array',
            'service_history' => 'array',
            'volunteer_hours' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(VolunteerProfileChange::class);
    }
}
