<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamReport extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_APPROVED = 'approved';

    public const DECISION_APPROVED = 'approved';
    public const DECISION_RETURNED = 'returned';

    protected $fillable = [
        'service_team_id',
        'branch_id',
        'team_report_form_id',
        'team_report_form_version',
        'reporting_period_start',
        'reporting_period_end',
        'template_version',
        'template_snapshot',
        'status',
        'version',
        'field_values',
        'attachments',
        'incidents',
        'concerns',
        'results',
        'recommendations',
        'is_locked',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_decision',
        'review_comments',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'reporting_period_start' => 'date',
            'reporting_period_end' => 'date',
            'template_snapshot' => 'array',
            'field_values' => 'array',
            'attachments' => 'array',
            'incidents' => 'array',
            'results' => 'array',
            'recommendations' => 'array',
            'is_locked' => 'boolean',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(TeamReportForm::class, 'team_report_form_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(TeamReportVersion::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
