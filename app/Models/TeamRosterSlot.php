<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamRosterSlot extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_REPLACEMENT_REQUESTED = 'replacement_requested';
    public const STATUS_SUBSTITUTED = 'substituted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'team_roster_id',
        'member_id',
        'service_team_assignment_id',
        'duty_label',
        'shift_label',
        'shift_date',
        'shift_start',
        'shift_end',
        'status',
        'member_response',
        'response_reason',
        'responded_at',
        'substitute_member_id',
        'replaced_slot_id',
        'conflict_flags',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'conflict_flags' => 'array',
            'responded_at' => 'datetime',
        ];
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(TeamRoster::class, 'team_roster_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function substituteMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'substitute_member_id');
    }

    public function replacedSlot(): BelongsTo
    {
        return $this->belongsTo(TeamRosterSlot::class, 'replaced_slot_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(TeamRosterSlot::class, 'replaced_slot_id');
    }
}
