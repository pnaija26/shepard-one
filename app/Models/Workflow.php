<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Story 9.2: reusable workflow definition container.
 */
class Workflow extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const MIGRATION_KEEP_LOCKED = 'keep_locked';
    public const MIGRATION_MIGRATE_PENDING = 'migrate_pending';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'branch_id',
        'status',
        'current_version',
        'migration_policy',
        'created_by',
        'updated_by',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
