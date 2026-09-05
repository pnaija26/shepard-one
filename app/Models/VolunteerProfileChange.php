<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VolunteerProfileChange extends Model
{
    public const SOURCE_SELF = 'self';
    public const SOURCE_COORDINATOR = 'coordinator';

    public const STATUS_APPLIED = 'applied';
    public const STATUS_PENDING = 'pending_verification';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';

    public $timestamps = false;

    protected $fillable = [
        'volunteer_profile_id',
        'field',
        'change_source',
        'previous_value',
        'new_value',
        'verification_status',
        'effective_from',
        'effective_to',
        'actor_id',
        'verified_by',
        'verified_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_value' => 'array',
            'new_value' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(VolunteerProfile::class, 'volunteer_profile_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
