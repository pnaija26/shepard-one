<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContributionReceipt extends Model
{
    public const STATUS_ISSUED = 'issued';
    public const STATUS_VOIDED = 'voided';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'receipt_number',
        'verification_code',
        'contribution_id',
        'status',
        'amount_cents',
        'currency',
        'category',
        'campaign_name',
        'branch_id',
        'member_id',
        'financial_fields',
        'delivered',
        'delivery_channel',
        'delivered_at',
        'issued_by',
        'issued_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'financial_fields' => 'array',
            'delivered' => 'boolean',
            'issued_at' => 'datetime',
            'delivered_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(ContributionAdjustment::class, 'receipt_id');
    }
}
