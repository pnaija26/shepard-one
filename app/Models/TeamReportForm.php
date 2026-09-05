<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamReportForm extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'name',
        'branch_id',
        'status',
        'current_version',
        'created_by',
        'updated_by',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TeamReportFormVersion::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TeamReportFormAssignment::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(ServiceTeam::class, 'team_report_form_assignments', 'team_report_form_id', 'service_team_id')
            ->withPivot(['form_version', 'assigned_at', 'assigned_by'])
            ->withTimestamps(false);
    }
}
