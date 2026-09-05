<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 10.3: immutable published content version of a message template.
 */
class MessageTemplateVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'message_template_id',
        'version',
        'status',
        'subject',
        'body',
        'variables_used',
        'last_validation',
        'effective_from',
        'effective_to',
        'published_at',
        'published_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'variables_used' => 'array',
            'last_validation' => 'array',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function previews(): HasMany
    {
        return $this->hasMany(MessageTemplatePreview::class);
    }

    public function isEffectiveAt(?\DateTimeInterface $at = null): bool
    {
        $at = $at ? Carbon::instance($at) : now();

        if ($this->status !== self::STATUS_PUBLISHED && $this->status !== self::STATUS_SUPERSEDED) {
            // Superseded versions remain valid for their historical effective window.
        }

        if ($this->effective_from !== null && $at->lt($this->effective_from)) {
            return false;
        }

        if ($this->effective_to !== null && $at->gt($this->effective_to)) {
            return false;
        }

        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_SUPERSEDED], true)
            && $this->published_at !== null;
    }
}
