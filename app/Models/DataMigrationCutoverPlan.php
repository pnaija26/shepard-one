<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataMigrationCutoverPlan extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_READY = 'ready';
    public const STATUS_UAT_SIGNED_OFF = 'uat_signed_off';
    public const STATUS_PRODUCTION_COMPLETE = 'production_complete';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_DISPOSED = 'disposed';

    protected $fillable = [
        'reference',
        'data_migration_mapping_id',
        'environment',
        'maintenance_window_start',
        'maintenance_window_end',
        'backup_confirmed',
        'rollback_criteria',
        'acceptance_thresholds',
        'status',
        'uat_signed_off_by',
        'uat_signed_off_at',
        'go_live_approved_by',
        'go_live_approved_at',
        'owner_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'backup_confirmed' => 'boolean',
            'rollback_criteria' => 'array',
            'acceptance_thresholds' => 'array',
            'maintenance_window_start' => 'datetime',
            'maintenance_window_end' => 'datetime',
            'uat_signed_off_at' => 'datetime',
            'go_live_approved_at' => 'datetime',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(DataMigrationMapping::class, 'data_migration_mapping_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(DataMigrationRun::class);
    }
}
