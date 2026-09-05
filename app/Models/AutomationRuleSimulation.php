<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 9.4: retained simulation evidence for an automation rule version.
 */
class AutomationRuleSimulation extends Model
{
    protected $fillable = [
        'automation_rule_version_id',
        'sample_payload',
        'result',
        'passed',
        'ran_by',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'sample_payload' => 'array',
            'result' => 'array',
            'passed' => 'boolean',
            'ran_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationRuleVersion::class, 'automation_rule_version_id');
    }
}
