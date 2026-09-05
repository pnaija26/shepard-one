<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchContent extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'reference',
        'content_type',
        'title',
        'branch_id',
        'status',
        'current_version',
        'approved_version',
        'visibility',
        'audience_type',
        'audience_params',
        'device_target',
        'publish_from',
        'publish_to',
        'published_at',
        'withdrawn_at',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'audience_params' => 'array',
            'publish_from' => 'datetime',
            'publish_to' => 'datetime',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ChurchContentVersion::class);
    }

    public function isWithinPublishWindow(?\Carbon\Carbon $at = null): bool
    {
        $at ??= now();
        if ($this->publish_from && $at->lt($this->publish_from)) {
            return false;
        }
        if ($this->publish_to && $at->gt($this->publish_to)) {
            return false;
        }

        return true;
    }
}
