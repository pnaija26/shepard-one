<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.4: evaluation/skip/retry/quarantine log (sanitized).
 */
class AutomationRuleEvaluation extends Model
{
    public const OUTCOME_MATCHED = 'matched';
    public const OUTCOME_EXECUTED = 'executed';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_RETRIED = 'retried';
    public const OUTCOME_QUARANTINED = 'quarantined';

    protected $fillable = [
        'automation_rule_id',
        'automation_rule_version_id',
        'event_type',
        'event_key',
        'outcome',
        'skip_reason',
        'attempt',
        'result',
        'action_type',
        'action_reference_type',
        'action_reference_id',
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

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'automation_rule_version_id');
    }
}
