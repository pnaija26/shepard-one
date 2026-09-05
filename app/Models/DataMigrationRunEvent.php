<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataMigrationRunEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'data_migration_run_id',
        'stage',
        'action',
        'operator_id',
        'detail',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DataMigrationRun::class, 'data_migration_run_id');
    }
}
