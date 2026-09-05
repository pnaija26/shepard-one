<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WelfareApprovalConfigVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'welfare_approval_config_id',
        'version',
        'status',
        'thresholds',
        'published_by',
        'published_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'thresholds' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function config(): BelongsTo
    {
        return $this->belongsTo(WelfareApprovalConfig::class, 'welfare_approval_config_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WelfareApprovalStep::class, 'approval_config_version_id');
    }
}
