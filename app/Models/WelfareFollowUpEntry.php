<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WelfareFollowUpEntry extends Model
{
    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_REASSIGN = 'reassign';
    public const TYPE_ESCALATION = 'escalation';
    public const TYPE_CLOSURE = 'closure';
    public const TYPE_REMINDER = 'reminder';

    protected $fillable = [
        'welfare_request_id',
        'branch_id',
        'entry_type',
        'outcome',
        'further_action',
        'notes',
        'follow_up_due_on',
        'from_officer_id',
        'to_officer_id',
        'closure_reason',
        'evidence',
        'recorded_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'follow_up_due_on' => 'date',
            'evidence' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function fromOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_officer_id');
    }

    public function toOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_officer_id');
    }
}
