<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTeamAssignment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRANSFERRED = 'transferred';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'service_team_id',
        'member_id',
        'team_role',
        'sub_team',
        'shift_label',
        'responsibilities',
        'status',
        'effective_from',
        'effective_to',
        'team_config_version',
        'override_applied',
        'override_reason',
        'assigned_by',
        'approved_by',
        'approved_at',
        'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'responsibilities' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'override_applied' => 'boolean',
            'approved_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ServiceTeamAssignmentEvent::class);
    }
}
