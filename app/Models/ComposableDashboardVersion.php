<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComposableDashboardVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'composable_dashboard_id',
        'version',
        'status',
        'widgets',
        'role_ids',
        'scope',
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
            'widgets' => 'array',
            'role_ids' => 'array',
            'scope' => 'array',
            'last_validation' => 'array',
            'warnings' => 'array',
            'published_at' => 'datetime',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(ComposableDashboard::class, 'composable_dashboard_id');
    }

    public function previews(): HasMany
    {
        return $this->hasMany(ComposableDashboardPreview::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
