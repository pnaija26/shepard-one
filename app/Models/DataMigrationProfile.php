<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationProfile extends Model
{
    protected $fillable = [
        'data_migration_source_id',
        'columns',
        'summary',
        'sensitive_fields',
        'duplicate_keys',
        'profiled_at',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'summary' => 'array',
            'sensitive_fields' => 'array',
            'duplicate_keys' => 'array',
            'profiled_at' => 'datetime',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataMigrationSource::class, 'data_migration_source_id');
    }
}
