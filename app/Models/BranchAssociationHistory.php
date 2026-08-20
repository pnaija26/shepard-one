<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 1.5: append-only log of a person's branch associations over time.
 *
 * Each row says "person X was associated with branch Y from started_at until
 * ended_at (NULL = still current)". When a movement is applied we close out the
 * previous association and open a new one, so the full timeline of where an
 * identity has been is always reconstructable — member history preserved without
 * ever creating duplicate people.
 */
class BranchAssociationHistory extends Model
{
    // Migration creates "branch_association_history" (singular); Eloquent's
    // default pluralization would look for "...histories", so pin it explicitly.
    protected $table = 'branch_association_history';

    protected $fillable = [
        'person_id',
        'branch_id',
        'started_at',
        'ended_at',
        'source',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'person_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    /** The person's currently active association (ended_at IS NULL). */
    public static function currentFor(int $personId): ?self
    {
        return static::where('person_id', $personId)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();
    }

    /** Full timeline for a person, oldest first. */
    public static function timelineFor(int $personId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('person_id', $personId)
            ->orderBy('started_at')
            ->get();
    }
}
