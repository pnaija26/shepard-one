<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 10.3: reusable versioned message template.
 */
class MessageTemplate extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'name',
        'slug',
        'scenario',
        'channel',
        'language',
        'branch_id',
        'status',
        'current_version',
        'description',
        'created_by',
        'updated_by',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MessageTemplateVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
