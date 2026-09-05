<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationImportRecord extends Model
{
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'data_migration_run_id',
        'import_key',
        'source_row_number',
        'target_type',
        'target_id',
        'status',
        'lineage',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'lineage' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataMigrationRun::class, 'data_migration_run_id');
    }
}
