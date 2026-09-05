<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 9.4: versioned automation rule definition.
 */
class AutomationRuleVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'automation_rule_id',
        'version',
        'status',
        'event_type',
        'conditions',
        'action_type',
        'action_params',
        'scope_type',
        'priority',
        'stop_behavior',
        'failure_policy',
        'effective_from',
        'effective_to',
        'requires_consent',
        'last_validation',
        'published_at',
        'published_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'action_params' => 'array',
            'last_validation' => 'array',
            'requires_consent' => 'boolean',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(AutomationRuleSimulation::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isEffectiveAt(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? \Carbon\Carbon::instance($at) : now();

        if ($this->effective_from !== null && $at->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to !== null && $at->gt($this->effective_to)) {
            return false;
        }

        return true;
    }
}
