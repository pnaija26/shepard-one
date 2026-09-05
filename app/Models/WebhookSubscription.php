<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookSubscription extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_QUARANTINED = 'quarantined';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'reference',
        'name',
        'endpoint_url',
        'signing_secret_encrypted',
        'signing_secret_hint',
        'allowed_event_types',
        'branch_id',
        'status',
        'sensitive_payload_approved',
        'verification_token',
        'verified_at',
        'consecutive_failures',
        'quarantined_at',
        'revoked_at',
        'created_by',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'allowed_event_types' => 'array',
            'sensitive_payload_approved' => 'boolean',
            'signing_secret_encrypted' => 'encrypted',
            'verified_at' => 'datetime',
            'quarantined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'signing_secret_encrypted',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isDeliverable(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->revoked_at === null
            && $this->verified_at !== null;
    }
}
