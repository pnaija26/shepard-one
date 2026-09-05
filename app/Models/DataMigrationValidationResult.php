<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationValidationResult extends Model
{
    public const OUTCOME_ACCEPTED = 'accepted';
    public const OUTCOME_CORRECTED = 'corrected';
    public const OUTCOME_REJECTED = 'rejected';
    public const OUTCOME_DUPLICATE_REVIEW = 'duplicate_review';

    protected $fillable = [
        'data_migration_validation_run_id',
        'source_row_number',
        'outcome',
        'reasons',
        'original_data',
        'mapped_data',
    ];

    protected function casts(): array
    {
        return [
            'reasons' => 'array',
            'original_data' => 'array',
            'mapped_data' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataMigrationValidationRun::class, 'data_migration_validation_run_id');
    }
}
