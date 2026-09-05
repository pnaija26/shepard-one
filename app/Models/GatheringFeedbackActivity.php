<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatheringFeedbackActivity extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'gathering_feedback_id',
        'activity_type',
        'notes',
        'assignee_id',
        'notify_submitter',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'notify_submitter' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function feedback(): BelongsTo
    {
        return $this->belongsTo(GatheringFeedback::class, 'gathering_feedback_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
