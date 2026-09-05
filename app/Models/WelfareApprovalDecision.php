<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable approval decision history (Story 7.3).
 */
class WelfareApprovalDecision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'welfare_approval_step_id',
        'welfare_request_id',
        'level',
        'decision',
        'reason',
        'decided_by',
        'config_version',
        'decided_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WelfareApprovalStep::class, 'welfare_approval_step_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
