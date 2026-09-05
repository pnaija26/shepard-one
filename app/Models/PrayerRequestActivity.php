<?php

namespace App\Models;

use App\Services\PrayerRequestException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 8.4: immutable prayer request processing activity.
 */
class PrayerRequestActivity extends Model
{
    protected $fillable = [
        'prayer_request_id',
        'activity_type',
        'status_after',
        'notes',
        'restricted_notes',
        'from_officer_id',
        'to_officer_id',
        'actor_id',
        'metadata',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'restricted_notes' => 'encrypted',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new PrayerRequestException('Prayer request activities cannot be modified.', 'immutable', 422);
        });

        static::deleting(function () {
            throw new PrayerRequestException('Prayer request activities cannot be deleted.', 'immutable', 422);
        });
    }

    public function prayerRequest(): BelongsTo
    {
        return $this->belongsTo(PrayerRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function fromOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_officer_id');
    }

    public function toOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_officer_id');
    }
}
