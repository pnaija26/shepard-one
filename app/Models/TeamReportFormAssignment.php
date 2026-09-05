<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamReportFormAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'team_report_form_id',
        'service_team_id',
        'form_version',
        'assigned_by',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(TeamReportForm::class, 'team_report_form_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'service_team_id');
    }
}
