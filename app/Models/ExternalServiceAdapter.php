<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalServiceAdapter extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_TESTED = 'tested';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DRAINING = 'draining';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'reference',
        'name',
        'adapter_type',
        'provider',
        'environment',
        'branch_id',
        'credentials_encrypted',
        'credential_hints',
        'mappings',
        'quotas',
        'callback_urls',
        'feature_flags',
        'status',
        'drain_policy',
        'replaced_by_id',
        'effective_at',
        'disabled_at',
        'last_tested_at',
        'last_test_result',
        'last_test_details',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'credentials_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'credentials_encrypted' => 'encrypted:array',
            'credential_hints' => 'array',
            'mappings' => 'array',
            'quotas' => 'array',
            'callback_urls' => 'array',
            'feature_flags' => 'array',
            'last_test_details' => 'array',
            'effective_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(ExternalAdapterOperation::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->disabled_at === null;
    }
}
