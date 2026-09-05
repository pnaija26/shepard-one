<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamRoster extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'service_team_id',
        'branch_id',
        'roster_type',
        'title',
        'status',
        'gathering_key',
        'gathering_id',
        'period_start',
        'period_end',
        'staffing_requirements',
        'conflict_summary',
        'override_applied',
        'override_reason',
        'published_at',
        'published_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'staffing_requirements' => 'array',
            'conflict_summary' => 'array',
            'override_applied' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(TeamRosterSlot::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TeamRosterEvent::class);
    }
}
