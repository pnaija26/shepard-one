<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomReportVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'custom_report_id',
        'version',
        'status',
        'definition',
        'last_validation',
        'warnings',
        'published_at',
        'published_by',
        'effective_from',
        'effective_to',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'last_validation' => 'array',
            'warnings' => 'array',
            'published_at' => 'datetime',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CustomReport::class, 'custom_report_id');
    }

    public function previews(): HasMany
    {
        return $this->hasMany(CustomReportPreview::class);
    }
}
