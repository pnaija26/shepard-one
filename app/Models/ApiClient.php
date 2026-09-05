<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const PRINCIPAL_MACHINE = 'machine';

    protected $fillable = [
        'reference',
        'name',
        'client_id',
        'secret_hash',
        'principal_type',
        'allowed_scopes',
        'user_id',
        'branch_id',
        'rate_limit_per_minute',
        'status',
        'last_used_at',
        'revoked_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allowed_scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(ApiAccessEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->revoked_at === null;
    }
}
