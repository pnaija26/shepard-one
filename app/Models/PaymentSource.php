<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSource extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_TESTED = 'tested';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'reference',
        'name',
        'provider',
        'environment',
        'currency',
        'branch_id',
        'supported_categories',
        'branch_mapping',
        'api_key_encrypted',
        'webhook_secret_encrypted',
        'api_key_hint',
        'webhook_secret_hint',
        'enabled',
        'status',
        'last_tested_at',
        'last_test_result',
        'last_test_details',
        'configured_by',
        'updated_by',
    ];

    protected $hidden = [
        'api_key_encrypted',
        'webhook_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'supported_categories' => 'array',
            'branch_mapping' => 'array',
            'api_key_encrypted' => 'encrypted',
            'webhook_secret_encrypted' => 'encrypted',
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_details' => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'branch_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(PaymentWebhookEvent::class);
    }
}
