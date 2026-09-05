<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpEscalation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'follow_up_id',
        'trigger_type',
        'from_assignee_id',
        'to_assignee_id',
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

    public function followUp(): BelongsTo
    {
        return $this->belongsTo(FollowUp::class);
    }

    public function fromAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_assignee_id');
    }

    public function toAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_assignee_id');
    }
}
