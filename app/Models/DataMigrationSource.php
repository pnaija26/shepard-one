<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DataMigrationSource extends Model
{
    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PROFILED = 'profiled';
    public const STATUS_CONNECTED = 'connected';

    protected $fillable = [
        'reference',
        'name',
        'source_type',
        'branch_id',
        'status',
        'storage_disk',
        'storage_path',
        'file_hash',
        'row_count',
        'classification',
        'retention_ends_at',
        'connection_config',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'connection_config' => 'array',
            'retention_ends_at' => 'datetime',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(DataMigrationProfile::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(DataMigrationMapping::class);
    }
}
