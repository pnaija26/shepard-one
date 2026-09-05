<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NewsletterVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'newsletter_id',
        'version',
        'status',
        'subject',
        'preview_text',
        'sections',
        'has_unsubscribe',
        'last_validation',
        'approved_at',
        'approved_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'has_unsubscribe' => 'boolean',
            'last_validation' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    public function previews(): HasMany
    {
        return $this->hasMany(NewsletterPreview::class);
    }
}
