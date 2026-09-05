<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 8.2: care case escalation with acknowledgement.
 */
class CareCaseEscalation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'care_case_id',
        'trigger_type',
        'from_officer_id',
        'to_officer_id',
        'escalated_by',
        'acknowledged_by',
        'acknowledged_at',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function careCase(): BelongsTo
    {
        return $this->belongsTo(CareCase::class, 'care_case_id');
    }

    public function fromOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_officer_id');
    }

    public function toOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_officer_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
