<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMigrationMappingVersion extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'data_migration_mapping_id',
        'version_number',
        'field_mappings',
        'transformations',
        'defaults',
        'duplicate_rules',
        'validation_errors',
        'status',
        'created_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'field_mappings' => 'array',
            'transformations' => 'array',
            'defaults' => 'array',
            'duplicate_rules' => 'array',
            'validation_errors' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(DataMigrationMapping::class, 'data_migration_mapping_id');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(DataMigrationMappingTest::class);
    }

    public function validationRuns(): HasMany
    {
        return $this->hasMany(DataMigrationValidationRun::class);
    }
}
