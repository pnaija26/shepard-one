<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTeamAssignmentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_team_assignment_id',
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

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ServiceTeamAssignment::class, 'service_team_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
