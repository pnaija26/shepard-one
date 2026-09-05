<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 10.4: once-per-period send/skip log for a milestone greeting.
 */
class MilestoneGreetingEvaluation extends Model
{
    public const OUTCOME_SENT = 'sent';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';

    protected $fillable = [
        'member_id',
        'milestone_type',
        'period_key',
        'outcome',
        'skip_reason',
        'milestone_greeting_config_id',
        'communication_id',
        'message_template_version_id',
        'result',
        'branch_id',
        'actor_id',
        'evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'evaluated_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(MilestoneGreetingConfig::class, 'milestone_greeting_config_id');
    }

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }
}
