<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WelfareApprovalConfig extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'name',
        'branch_id',
        'status',
        'current_version',
        'published_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WelfareApprovalConfigVersion::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }
}
