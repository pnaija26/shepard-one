<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMigrationValidationRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'data_migration_mapping_version_id',
        'status',
        'summary',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DataMigrationMappingVersion::class, 'data_migration_mapping_version_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(DataMigrationValidationResult::class);
    }
}
