<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WelfareApprovalStep extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'welfare_request_id',
        'approval_config_version_id',
        'sequence',
        'level',
        'status',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WelfareRequest::class, 'welfare_request_id');
    }

    public function configVersion(): BelongsTo
    {
        return $this->belongsTo(WelfareApprovalConfigVersion::class, 'approval_config_version_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(WelfareApprovalDecision::class);
    }
}
