<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 8.3: auditable confidentiality narrowing / withdrawal event.
 */
class PrayerRequestConfidentialityEvent extends Model
{
    public $timestamps = false;

    public const TYPE_NARROWED = 'narrowed';
    public const TYPE_WITHDRAWN = 'withdrawn';
    public const TYPE_INITIAL = 'initial';

    protected $fillable = [
        'prayer_request_id',
        'from_confidentiality',
        'to_confidentiality',
        'change_type',
        'reason',
        'actor_id',
        'effective_at',
        'propagation_completed_at',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'effective_at' => 'datetime',
            'propagation_completed_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function prayerRequest(): BelongsTo
    {
        return $this->belongsTo(PrayerRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
