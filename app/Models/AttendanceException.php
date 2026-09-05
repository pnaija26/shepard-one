<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AttendanceException extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FLAGGED_REVIEW = 'flagged_review';

    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'rule_version_id',
        'rule_version',
        'rule_type',
        'subject_type',
        'subject_id',
        'branch_id',
        'service_type',
        'period_key',
        'status',
        'summary',
        'evidence',
        'resolution_reason',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AttendanceExceptionRule::class, 'rule_id');
    }

    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(AttendanceExceptionRuleVersion::class, 'rule_version_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
