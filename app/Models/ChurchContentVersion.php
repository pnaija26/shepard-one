<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChurchContentVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'church_content_id',
        'version',
        'status',
        'title',
        'body',
        'media',
        'links',
        'last_validation',
        'approved_at',
        'approved_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'media' => 'array',
            'links' => 'array',
            'last_validation' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(ChurchContent::class, 'church_content_id');
    }

    public function previews(): HasMany
    {
        return $this->hasMany(ChurchContentPreview::class);
    }
}
