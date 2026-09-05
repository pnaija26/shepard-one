<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamRosterEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'team_roster_id',
        'team_roster_slot_id',
        'event_type',
        'reason',
        'metadata',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(TeamRoster::class, 'team_roster_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(TeamRosterSlot::class, 'team_roster_slot_id');
    }
}
