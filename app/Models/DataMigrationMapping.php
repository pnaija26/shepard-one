<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMigrationMapping extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'data_migration_source_id',
        'name',
        'target_entity',
        'branch_id',
        'current_version',
        'status',
        'created_by',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataMigrationSource::class, 'data_migration_source_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DataMigrationMappingVersion::class)->orderByDesc('version_number');
    }

    public function cutoverPlans(): HasMany
    {
        return $this->hasMany(DataMigrationCutoverPlan::class);
    }

    public function currentVersionRecord(): BelongsTo
    {
        return $this->belongsTo(DataMigrationMappingVersion::class, 'current_version', 'version_number')
            ->whereColumn('data_migration_mapping_versions.data_migration_mapping_id', 'data_migration_mappings.id');
    }
}
