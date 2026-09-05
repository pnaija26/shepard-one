<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WelfareFollowUpReminder extends Model
{
    public const TYPE_OVERDUE_REMINDER = 'overdue_reminder';
    public const TYPE_OVERDUE_ESCALATION = 'overdue_escalation';

    protected $fillable = [
        'welfare_request_id',
        'reminder_type',
        'sent_at',
        'actor_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
