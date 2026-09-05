<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMigrationRun extends Model
{
    public const TYPE_TEST = 'test';
    public const TYPE_PRODUCTION = 'production';

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'data_migration_cutover_plan_id',
        'data_migration_mapping_version_id',
        'run_type',
        'idempotency_key',
        'status',
        'duration_ms',
        'summary',
        'reconciliation',
        'performance',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'reconciliation' => 'array',
            'performance' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DataMigrationCutoverPlan::class, 'data_migration_cutover_plan_id');
    }

    public function importRecords(): HasMany
    {
        return $this->hasMany(DataMigrationImportRecord::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(DataMigrationRunEvent::class);
    }
}
