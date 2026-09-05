<?php

namespace App\Models;

use App\Services\CareCaseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 8.2: immutable chronological care case activity entry.
 */
class CareCaseActivity extends Model
{
    protected $fillable = [
        'care_case_id',
        'activity_type',
        'outcome',
        'notes',
        'restricted_note',
        'next_follow_up_on',
        'actor_id',
        'responsible_officer_id',
        'metadata',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'restricted_note' => 'encrypted',
            'next_follow_up_on' => 'date',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new CareCaseException('Care case activities cannot be modified.', 'immutable', 422);
        });

        static::deleting(function () {
            throw new CareCaseException('Care case activities cannot be deleted.', 'immutable', 422);
        });
    }

    public function careCase(): BelongsTo
    {
        return $this->belongsTo(CareCase::class, 'care_case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function responsibleOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_officer_id');
    }
}
