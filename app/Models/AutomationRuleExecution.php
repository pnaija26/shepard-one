<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.4: idempotent execution marker (once per rule + event key).
 */
class AutomationRuleExecution extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_QUARANTINED = 'quarantined';

    protected $fillable = [
        'automation_rule_id',
        'automation_rule_version_id',
        'event_key',
        'action_type',
        'status',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
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
