<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTeamChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_team_id',
        'change_type',
        'config_version',
        'before_state',
        'after_state',
        'summary',
        'changed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }
}
