<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 14.3: permission-scoped global search index entry.
 */
class ChurchSearchEntry extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DELETED = 'deleted';

    protected $fillable = [
        'record_type',
        'record_id',
        'branch_id',
        'title',
        'snippet',
        'keywords',
        'required_permission',
        'sensitivity',
        'status',
        'source_updated_at',
        'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'source_updated_at' => 'datetime',
            'indexed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }
}
