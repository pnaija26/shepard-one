<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationMappingTest extends Model
{
    protected $fillable = [
        'data_migration_mapping_version_id',
        'sample_size',
        'results',
        'passed',
        'run_at',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'passed' => 'boolean',
            'run_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DataMigrationMappingVersion::class, 'data_migration_mapping_version_id');
    }
}
