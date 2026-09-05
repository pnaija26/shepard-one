<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdMembership extends Model
{
    public const TYPE_HEAD = 'head';
    public const TYPE_SPOUSE = 'spouse';
    public const TYPE_CHILD = 'child';
    public const TYPE_DEPENDANT = 'dependant';

    protected $fillable = [
        'household_id',
        'member_id',
        'relationship_type',
        'started_at',
        'ended_at',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public static function activeForMember(int $memberId): ?self
    {
        return static::query()
            ->where('member_id', $memberId)
            ->whereNull('ended_at')
            ->first();
    }
}
