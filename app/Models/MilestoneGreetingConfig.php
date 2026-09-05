<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 10.4: which published template greets a milestone type in a branch.
 */
class MilestoneGreetingConfig extends Model
{
    protected $fillable = [
        'branch_id',
        'milestone_type',
        'message_template_id',
        'channels',
        'enabled',
        'team_alerts_enabled',
        'team_alert_permission',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'enabled' => 'boolean',
            'team_alerts_enabled' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(MilestoneGreetingEvaluation::class);
    }
}
