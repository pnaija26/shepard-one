<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalIncidentEscalation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'operational_incident_id',
        'trigger_type',
        'from_owner_id',
        'to_owner_id',
        'branch_id',
        'escalated_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(OperationalIncident::class, 'operational_incident_id');
    }
}
