<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceTeamConfigVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'service_team_id',
        'version',
        'config',
        'effective_from',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'effective_from' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }
}
