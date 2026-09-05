<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 9.4: event-driven automation rule container.
 */
class AutomationRule extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'branch_id',
        'status',
        'current_version',
        'enabled',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationRuleVersion::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(AutomationRuleEvaluation::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationRuleExecution::class);
    }
}
