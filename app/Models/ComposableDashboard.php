<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComposableDashboard extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'branch_id',
        'status',
        'current_version',
        'created_by',
        'updated_by',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ComposableDashboardVersion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
