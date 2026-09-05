<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTeam extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'branch_id',
        'department_id',
        'name',
        'category',
        'description',
        'leaders',
        'required_skills',
        'minimum_staffing',
        'schedules',
        'objectives',
        'attendance_rules',
        'reporting_template',
        'approval_hierarchy',
        'status',
        'current_config_version',
        'created_by',
        'updated_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'leaders' => 'array',
            'required_skills' => 'array',
            'minimum_staffing' => 'array',
            'schedules' => 'array',
            'objectives' => 'array',
            'attendance_rules' => 'array',
            'reporting_template' => 'array',
            'approval_hierarchy' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'department_id');
    }

    public function configVersions(): HasMany
    {
        return $this->hasMany(ServiceTeamConfigVersion::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(ServiceTeamChange::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ServiceTeamAssignment::class);
    }

    public function rosters(): HasMany
    {
        return $this->hasMany(TeamRoster::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(TeamOccurrence::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TeamReport::class);
    }
}
