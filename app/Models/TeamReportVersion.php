<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamReportVersion extends Model
{
    public const CHANGE_DRAFT_SAVED = 'draft_saved';
    public const CHANGE_SUBMITTED = 'submitted';
    public const CHANGE_RETURNED = 'returned';
    public const CHANGE_APPROVED = 'approved';

    public $timestamps = false;

    protected $fillable = [
        'team_report_id',
        'version',
        'change_type',
        'snapshot',
        'comments',
        'actor_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(TeamReport::class, 'team_report_id');
    }
}
